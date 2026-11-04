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

/**
 * The orders an event names by id in a list (`order_ids`), as the checkout success page does.
 *
 * Ids arrive as integers or as numeric strings; anything else in the list is not an order id and is left out.
 */
class OrderIdsReader implements OrderEventReaderInterface
{
    private const string DATA_KEY = 'order_ids';

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
        $orderIds = [];
        foreach (is_array($carried) ? $carried : [] as $value) {
            $orderId = is_int($value) || (is_string($value) && ctype_digit($value)) ? (int)$value : 0;
            if ($orderId > 0) {
                $orderIds[] = $orderId;
            }
        }

        return $this->eventOrdersFactory->create(['orderIds' => $orderIds]);
    }
}
