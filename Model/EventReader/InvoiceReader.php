<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\TestOrder\Model\EventReader;

use Hryvinskyi\TestOrder\Api\Data\EventOrdersInterface;
use Hryvinskyi\TestOrder\Api\Data\EventOrdersInterfaceFactory;
use Hryvinskyi\TestOrder\Api\OrderEventReaderInterface;
use Magento\Framework\Event;
use Magento\Sales\Api\Data\InvoiceInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Model\Order\Invoice;

/**
 * The order an invoice (`invoice`) was issued for, by the order id the invoice names.
 *
 * An invoice issued while its order is being placed, by a payment method that captures at placement, names no order
 * yet; the order it holds is handed over as the object, which is already there and is answered by its cart.
 */
class InvoiceReader implements OrderEventReaderInterface
{
    private const string DATA_KEY = 'invoice';

    /**
     * @param EventOrdersInterfaceFactory $eventOrdersFactory Creates what the reader found
     */
    public function __construct(
        private readonly EventOrdersInterfaceFactory $eventOrdersFactory
    ) {
    }

    /**
     * @inheritDoc
     */
    public function read(Event $event): EventOrdersInterface
    {
        $carried = $event->getData(self::DATA_KEY);
        $orderId = $carried instanceof InvoiceInterface ? (int)$carried->getOrderId() : 0;
        if ($orderId > 0) {
            return $this->eventOrdersFactory->create(['orderIds' => [$orderId]]);
        }

        // An invoice given its order has the key even for an order without an id. One given no order lacks it, and
        // asking it for one would build an empty order and keep it.
        $holdsItsOrder = $carried instanceof Invoice && $carried->hasData(InvoiceInterface::ORDER_ID);
        $order = $holdsItsOrder ? $carried->getOrder() : null;

        return $this->eventOrdersFactory->create(['orders' => $order instanceof OrderInterface ? [$order] : []]);
    }
}
