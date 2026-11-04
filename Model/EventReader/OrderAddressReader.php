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
use Magento\Sales\Api\Data\OrderAddressInterface;

/**
 * The order an order address belongs to, by the order id the address names.
 *
 * An address save carries the address twice, as `address` and as `data_object`; the key read is configured, so one
 * reader is wired per key. A quote address under either key is not an order address and is not read.
 */
class OrderAddressReader implements OrderEventReaderInterface
{
    /**
     * @param EventOrdersInterfaceFactory $eventOrdersFactory Creates what the reader found
     * @param string $dataKey Event data key the address is read from
     */
    public function __construct(
        private readonly EventOrdersInterfaceFactory $eventOrdersFactory,
        private readonly string $dataKey = 'address'
    ) {
    }

    /**
     * @inheritDoc
     */
    public function read(Event $event): EventOrdersInterface
    {
        $address = $event->getData($this->dataKey);
        $orderId = $address instanceof OrderAddressInterface ? (int)$address->getParentId() : 0;

        return $this->eventOrdersFactory->create(['orderIds' => $orderId > 0 ? [$orderId] : []]);
    }
}
