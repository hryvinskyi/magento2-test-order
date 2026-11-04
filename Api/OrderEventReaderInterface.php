<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\TestOrder\Api;

use Hryvinskyi\TestOrder\Api\Data\EventOrdersInterface;
use Magento\Framework\Event;

/**
 * Finds the orders an event is about in one kind of event data.
 *
 * Readers form an open pool (the `readers` argument of the plugin that keeps observers away from test orders): a
 * module whose observer receives an event shape no reader understands contributes a reader with a `di.xml` item.
 *
 * A reader never loads anything to answer: it reports what the event already holds, so reading an event costs
 * nothing and has no side effect.
 *
 * @api
 */
interface OrderEventReaderInterface
{
    /**
     * The orders this reader finds in the event.
     *
     * @param Event $event Event being observed
     * @return EventOrdersInterface Empty when the event holds nothing this reader understands
     */
    public function read(Event $event): EventOrdersInterface;
}
