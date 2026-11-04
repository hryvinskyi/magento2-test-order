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
use Hryvinskyi\TestOrder\Api\TestOrderRepositoryInterface;
use Hryvinskyi\TestOrder\Api\UndoRunnerInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\OrderManagementInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Psr\Log\LoggerInterface;

/**
 * Ends every test order placed from a recognised cart: cancel each that can be cancelled, make sure what placing each
 * did is taken back, then delete each cancelled one when the shop deletes cancelled test orders.
 *
 * Cancelling leaves an order that can no longer be cancelled as it is, and runs the undo steps through the
 * cancellation itself; the runner is asked again afterwards, which undoes an order that could not be cancelled and
 * does nothing for one already undone. An order past cancelling stays live and hidden from integrations, with nothing
 * left that would come back to it, so it is logged as a warning for a person to end.
 *
 * A cart can carry more than one order — placement can save an order and then fail, and the retry places another —
 * so the orders are found through the cart id, never only through the link on the record. Every order is attempted
 * even when an earlier one fails; the first failure is passed on once all were tried, and ending them again is safe:
 * a cancelled order is not cancelled twice, and an undone order is not undone twice.
 */
class AbandonTestOrder implements AbandonTestOrderInterface
{
    /**
     * @param TestOrderRepositoryInterface $testOrderRepository The test-cart records
     * @param CartOrdersInterface $cartOrders Finds the orders placed from the cart
     * @param OrderRepositoryInterface $orderRepository Reloads orders
     * @param OrderDeletionInterface $orderDeletion Deletes a cancelled order
     * @param OrderManagementInterface $orderManagement Cancels an order that can still be cancelled
     * @param UndoRunnerInterface $undoRunner Takes back what placing an order did, once per order
     * @param CleanupConfigInterface $config Whether a cancelled test order is deleted
     * @param AbandonResultInterfaceFactory $resultFactory Creates the answer
     * @param LoggerInterface $logger Where an order past cancelling is reported
     */
    public function __construct(
        private readonly TestOrderRepositoryInterface $testOrderRepository,
        private readonly CartOrdersInterface $cartOrders,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly OrderDeletionInterface $orderDeletion,
        private readonly OrderManagementInterface $orderManagement,
        private readonly UndoRunnerInterface $undoRunner,
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
                if ($newest->getState() !== Order::STATE_CANCELED) {
                    $leftOpen = $newest;
                }
            } catch (\Throwable $failure) {
                $firstFailure ??= $failure;
            }
        }

        if ($firstFailure !== null) {
            throw $firstFailure;
        }

        if ($newest === null) {
            throw new NoSuchEntityException(__('No order was placed from test cart %1.', $record->getQuoteId()));
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
     * @param int $orderId Order entity id
     * @return array{OrderInterface, bool} The order as it was left, and whether it was deleted
     * @throws \Throwable When loading, cancelling or deleting the order fails
     */
    private function end(int $orderId): array
    {
        $this->orderManagement->cancel($orderId);

        $order = $this->orderRepository->get($orderId);
        $this->undoRunner->undo($order);

        if ($order->getState() !== Order::STATE_CANCELED) {
            $this->reportPastCancelling($order);

            return [$order, false];
        }

        if (!$this->config->deletesCancelledTestOrders()) {
            return [$order, false];
        }

        $this->orderDeletion->delete($order);

        return [$order, true];
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
                'Test order %s could not be cancelled because it is in state "%s". It was undone and kept, stays '
                . 'hidden from opted-out integrations, and a person must end it.',
                (string)$order->getIncrementId(),
                (string)$order->getState()
            ),
            ['order_id' => $order->getEntityId(), 'quote_id' => $order->getQuoteId()]
        );
    }
}
