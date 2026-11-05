<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\TestOrder\Model;

use Hryvinskyi\TestOrder\Api\AbandonTestOrderInterface;
use Hryvinskyi\TestOrder\Api\CartOrdersInterface;
use Hryvinskyi\TestOrder\Api\CleanupConfigInterface;
use Hryvinskyi\TestOrder\Api\Data\AbandonResultInterface;
use Hryvinskyi\TestOrder\Api\Data\AbandonResultInterfaceFactory;
use Hryvinskyi\TestOrder\Api\OrderDeletionInterface;
use Hryvinskyi\TestOrder\Api\PaidTestOrderRefundInterface;
use Hryvinskyi\TestOrder\Api\TestOrderRepositoryInterface;
use Hryvinskyi\TestOrder\Api\UndoRunnerInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\OrderManagementInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Psr\Log\LoggerInterface;

/**
 * Ends every test order placed from a recognised cart: cancel each that can be cancelled, refund each that was paid,
 * make sure what placing each did is taken back, and delete each cancelled one when the shop deletes cancelled test
 * orders. An order is ended once it is cancelled or closed.
 *
 * Cancelling leaves an order that can no longer be cancelled as it is, and runs the undo steps through the
 * cancellation itself. A paid order is past cancelling, so it is refunded, which closes it. The runner is asked after
 * both, which undoes an order that was not cancelled and does nothing for one already undone. A refund that fails on
 * an order no part of which was cancelled leaves it not undone, so the clean-up job comes back for it. On an order
 * partly cancelled the cancellation has already undone it, so the failure reaches the caller and nothing comes back to
 * it. An order neither cancelled nor refunded stays live and hidden from integrations, with nothing left that would
 * come back to it, so it is logged as a warning for a person to end.
 *
 * A closed order is never deleted, whatever the shop does with cancelled ones: the setting names cancelled orders, and
 * a closed order holds an invoice and a credit memo.
 *
 * A cart can carry more than one order — placement can save an order and then fail, and the retry places another —
 * so the orders are found through the cart id, never only through the link on the record. Every order is attempted
 * even when an earlier one fails; the first failure is passed on once all were tried, and ending them again is safe:
 * a cancelled order is not cancelled twice, as the core decides that under the order's lock and nothing here reads the
 * order before it, a closed order is not refunded twice, and an undone order is not undone twice.
 */
class AbandonTestOrder implements AbandonTestOrderInterface
{
    /**
     * The states of an order nothing more is done for.
     */
    private const array ENDED_STATES = [Order::STATE_CANCELED, Order::STATE_CLOSED];

    /**
     * @param TestOrderRepositoryInterface $testOrderRepository The test-cart records
     * @param CartOrdersInterface $cartOrders Finds the orders placed from the cart
     * @param OrderRepositoryInterface $orderRepository Reloads orders
     * @param OrderDeletionInterface $orderDeletion Deletes a cancelled order
     * @param OrderManagementInterface $orderManagement Cancels an order that can still be cancelled
     * @param UndoRunnerInterface $undoRunner Takes back what placing an order did, once per order
     * @param PaidTestOrderRefundInterface $paidTestOrderRefund Ends an order that was paid
     * @param CleanupConfigInterface $config Whether a cancelled test order is deleted
     * @param AbandonResultInterfaceFactory $resultFactory Creates the answer
     * @param LoggerInterface $logger Where an order left open is reported
     */
    public function __construct(
        private readonly TestOrderRepositoryInterface $testOrderRepository,
        private readonly CartOrdersInterface $cartOrders,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly OrderDeletionInterface $orderDeletion,
        private readonly OrderManagementInterface $orderManagement,
        private readonly UndoRunnerInterface $undoRunner,
        private readonly PaidTestOrderRefundInterface $paidTestOrderRefund,
        private readonly CleanupConfigInterface $config,
        private readonly AbandonResultInterfaceFactory $resultFactory,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @inheritDoc
     */
    public function abandon(int $quoteId): AbandonResultInterface
    {
        $record = $this->testOrderRepository->getByQuoteId($quoteId);
        $orders = $this->cartOrders->ofCart($record->getQuoteId());
        if ($orders === []) {
            throw new NoSuchEntityException(__('No order was placed from test cart %1.', $record->getQuoteId()));
        }

        $firstFailure = null;
        $newest = null;
        $leftOpen = null;
        $allDeleted = true;
        foreach ($orders as $order) {
            try {
                [$newest, $deleted] = $this->end((int)$order->getEntityId());
                $allDeleted = $allDeleted && $deleted;
                if (!in_array($newest->getState(), self::ENDED_STATES, true)) {
                    $leftOpen = $newest;
                }
            } catch (\Throwable $failure) {
                $firstFailure ??= $failure;
            }
        }

        if ($firstFailure !== null) {
            throw $firstFailure;
        }

        $answered = $leftOpen ?? $newest;
        $incrementId = (string)$answered->getIncrementId();

        return $this->resultFactory->create([
            'state' => (string)$answered->getState(),
            'deleted' => $allDeleted,
            'incrementId' => $incrementId === '' ? null : $incrementId,
        ]);
    }

    /**
     * End one order of the cart.
     *
     * The cancellation is asked for whatever state the order is in, and the core declines one that cannot be cancelled,
     * a closed one among them. An order already closed gets no refund either, but it is still undone: a person may have
     * closed it, and an order never undone would keep its cart in the clean-up job's batch.
     *
     * @param int $orderId Order entity id
     * @return array{OrderInterface, bool} The order as it was left, and whether it was deleted
     * @throws \Throwable When loading, cancelling, refunding or deleting the order fails
     */
    private function end(int $orderId): array
    {
        // Nothing reads the order first. The core decides under the order's lock on what the shared repository holds,
        // so a copy read here would be what it decides on, and two overlapping abandons would both cancel the order and
        // return its stock twice.
        $this->orderManagement->cancel($orderId);

        $order = $this->orderRepository->get($orderId);

        // Before the undo, whose claim takes the cart out of the clean-up job, so that a failed refund is tried again.
        // Cancelling part of an order has claimed it already, and for that order nothing tries again.
        if (!in_array($order->getState(), self::ENDED_STATES, true)) {
            $order = $this->paidTestOrderRefund->refund($order);
        }

        $this->undoRunner->undo($order);

        if ($order->getState() === Order::STATE_CANCELED) {
            return [$order, $this->deleteWhenAsked($order)];
        }

        if ($order->getState() !== Order::STATE_CLOSED) {
            $this->reportPastCancelling($order);
        }

        return [$order, false];
    }

    /**
     * Delete a cancelled order when the shop deletes cancelled test orders.
     *
     * @param OrderInterface $order The cancelled order
     * @return bool Whether it was deleted
     * @throws \Throwable When deleting the order fails
     */
    private function deleteWhenAsked(OrderInterface $order): bool
    {
        if (!$this->config->deletesCancelledTestOrders()) {
            return false;
        }

        $this->orderDeletion->delete($order);

        return true;
    }

    /**
     * Report an order that stays live although it was abandoned.
     *
     * @param OrderInterface $order The order, undone and kept
     * @return void
     */
    private function reportPastCancelling(OrderInterface $order): void
    {
        $this->logger->warning(
            sprintf(
                'Test order %s could not be cancelled or refunded and is in state "%s". It was undone and kept, stays '
                . 'hidden from opted-out integrations, and a person must end it.',
                (string)$order->getIncrementId(),
                (string)$order->getState()
            ),
            ['order_id' => $order->getEntityId(), 'quote_id' => $order->getQuoteId()]
        );
    }
}
