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
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\Data\OrderPaymentInterface;
use Magento\Sales\Model\Order\Payment;

/**
 * The order a payment (`payment`) belongs to, by the order id the payment names.
 *
 * A payment of an order still being placed names no order yet; the order it holds is handed over as the object, which
 * is already there, loads nothing and is answered by its cart.
 */
class PaymentReader implements OrderEventReaderInterface
{
    private const string DATA_KEY = 'payment';

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
        $orderId = $carried instanceof OrderPaymentInterface ? (int)$carried->getParentId() : 0;
        if ($orderId > 0) {
            return $this->eventOrdersFactory->create(['orderIds' => [$orderId]]);
        }

        $order = $carried instanceof Payment ? $carried->getOrder() : null;

        return $this->eventOrdersFactory->create(['orders' => $order instanceof OrderInterface ? [$order] : []]);
    }
}
