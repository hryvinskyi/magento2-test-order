<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\TestOrder\Observer;

use Hryvinskyi\TestOrder\Api\TestCartRecordsInterface;
use Hryvinskyi\TestOrder\Api\TestOrdersInterface;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Sales\Api\Data\OrderInterface;

/**
 * Notes on a test cart's record which order was placed from it, once placement has succeeded
 * (`sales_model_service_quote_submit_success`).
 *
 * A convenience for whoever starts from the record: an order whose placement saved it but never succeeded is not
 * linked here, and is still found through its cart id. An ordinary order is only asked about, never written for.
 */
class LinkTestOrder implements ObserverInterface
{
    /**
     * @param TestOrdersInterface $testOrders Answers whether the order is a test order
     * @param TestCartRecordsInterface $records Records the link
     */
    public function __construct(
        private readonly TestOrdersInterface $testOrders,
        private readonly TestCartRecordsInterface $records
    ) {
    }

    /**
     * Link the placed order to its cart's record when it is a test order.
     *
     * @param Observer $observer Carries the placed `order`
     * @return void
     */
    public function execute(Observer $observer): void
    {
        $order = $observer->getEvent()->getData('order');
        if (!$order instanceof OrderInterface) {
            return;
        }

        $orderId = (int)$order->getEntityId();
        $quoteId = (int)$order->getQuoteId();
        if ($orderId <= 0 || $quoteId <= 0 || !$this->testOrders->isTestOrder($order)) {
            return;
        }

        $this->records->linkOrder($quoteId, $orderId);
    }
}
