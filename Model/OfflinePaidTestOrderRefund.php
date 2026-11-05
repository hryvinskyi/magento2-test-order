<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\TestOrder\Model;

use Hryvinskyi\TestOrder\Api\LivePaymentsDeclarationInterface;
use Hryvinskyi\TestOrder\Api\PaidTestOrderRefundInterface;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Sales\Api\CreditmemoManagementInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Creditmemo\Item;
use Magento\Sales\Model\Order\CreditmemoFactory;
use Magento\Sales\Model\OrderMutexInterface;

/**
 * Refunds a paid test order with an offline credit memo: the whole order, every item back to stock.
 *
 * Offline means the shop only records the refund. No transaction is asked for, so no call reaches a payment provider:
 * the order's payment method is asked to refund only when it declares itself offline. No e-mail is sent, as nothing
 * here asks for the credit memo's notification.
 *
 * A credit memo that moves no money is true only of test money. On a shop that declares live payments it would hide a
 * real payment, so the order is refused and a person sees it. That refusal depends on a person making the declaration,
 * so this is safe only while a guard covers each live provider the shop has, store-wide, or by cart where the method is
 * known in the request that carries the proof: a test order must never get as far as a live payment on a shop that has
 * not declared one.
 *
 * Two abandons of one order can run at once: a late one that outlived its client, and the retry that client sent. Both
 * hold a copy read while the order was open, so the decision is taken on a copy read under the order's lock, and the
 * second one finds the order closed instead of writing a second credit memo and returning the stock twice.
 */
class OfflinePaidTestOrderRefund implements PaidTestOrderRefundInterface
{
    /**
     * What the stock side of a refund reads from a credit memo item. The item declares no setter for it.
     */
    private const string BACK_TO_STOCK = 'back_to_stock';

    /**
     * @param LivePaymentsDeclarationInterface $livePayments Whether this shop declares live payments
     * @param CreditmemoFactory $creditmemoFactory Creates the credit memo of the whole order
     * @param CreditmemoManagementInterface $creditmemoManagement Records the refund
     * @param OrderMutexInterface $orderMutex Holds the order from the decision to the write
     * @param OrderRepositoryInterface $orderRepository Reads the order as it stands under the lock
     * @param SearchCriteriaBuilder $searchCriteriaBuilder Builds the search; starts afresh after each one it builds
     */
    public function __construct(
        private readonly LivePaymentsDeclarationInterface $livePayments,
        private readonly CreditmemoFactory $creditmemoFactory,
        private readonly CreditmemoManagementInterface $creditmemoManagement,
        private readonly OrderMutexInterface $orderMutex,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly SearchCriteriaBuilder $searchCriteriaBuilder
    ) {
    }

    /**
     * @inheritDoc
     */
    public function refund(OrderInterface $order): OrderInterface
    {
        if ($this->livePayments->allowsTestOrdersOnLivePayments()) {
            return $order;
        }

        $orderId = (int)$order->getEntityId();
        $asItStands = $this->orderMutex->execute(
            $orderId,
            fn (): ?OrderInterface => $this->refundAsItStands($orderId)
        );

        return $asItStands instanceof OrderInterface ? $asItStands : $order;
    }

    /**
     * Refund the order as it stands now. Runs under the order's lock, in the lock's transaction.
     *
     * @param int $orderId Order entity id
     * @return OrderInterface|null The order read here, saved by the refund when there was one; null when none exists
     */
    private function refundAsItStands(int $orderId): ?OrderInterface
    {
        $order = $this->orderAsItStands($orderId);
        if (!$order instanceof Order || !$order->canCreditmemo()) {
            return $order;
        }

        $creditmemo = $this->creditmemoFactory->createByOrder($order);
        // Only the admin's credit memo form marks items. An item left unmarked is written off, and the stock the order
        // held is lost for good.
        foreach ($creditmemo->getItems() as $item) {
            if ($item instanceof Item) {
                $item->setData(self::BACK_TO_STOCK, true);
            }
        }

        $this->creditmemoManagement->refund($creditmemo, true);

        return $order;
    }

    /**
     * The order read from the database.
     *
     * A search and not a get: the repository's get answers from what it remembers, and that memory is the copy a racing
     * refund went stale on.
     *
     * @param int $orderId Order entity id
     * @return OrderInterface|null Null when no such order exists
     */
    private function orderAsItStands(int $orderId): ?OrderInterface
    {
        $criteria = $this->searchCriteriaBuilder->addFilter(OrderInterface::ENTITY_ID, $orderId)->create();

        return array_values($this->orderRepository->getList($criteria)->getItems())[0] ?? null;
    }
}
