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
use Magento\Quote\Api\Data\CartInterface;

/**
 * The cart an event carries (`quote`), by its id: the order placed from it is what the event is about.
 *
 * Checkout events before the order exists carry only the cart, so this is how such an event is told to be about a
 * test order. A cart that has not been saved has no id and is not read.
 */
class QuoteReader implements OrderEventReaderInterface
{
    private const string DATA_KEY = 'quote';

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
        $cart = $event->getData(self::DATA_KEY);
        $quoteId = $cart instanceof CartInterface ? (int)$cart->getId() : 0;

        return $this->eventOrdersFactory->create(['quoteIds' => $quoteId > 0 ? [$quoteId] : []]);
    }
}
