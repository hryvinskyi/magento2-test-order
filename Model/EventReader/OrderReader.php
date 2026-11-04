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

/**
 * The order an event carries itself (`order`), saved or still being placed.
 *
 * An order still being placed has no id yet; it is handed over as the object, which is answered by its cart.
 */
class OrderReader implements OrderEventReaderInterface
{
    private const string DATA_KEY = 'order';

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
        $order = $event->getData(self::DATA_KEY);

        return $this->eventOrdersFactory->create(['orders' => $order instanceof OrderInterface ? [$order] : []]);
    }
}
