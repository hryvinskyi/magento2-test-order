<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\TestOrder\Api\Data;

use Magento\Sales\Api\Data\OrderInterface;

/**
 * The orders one event is about, as an event reader found them: order objects the event carries, ids of saved
 * orders named by what it carries (an address, a document, a list of ids), and ids of carts being checked out, whose
 * order may not exist yet.
 *
 * Created through the generated `EventOrdersInterfaceFactory`:
 * `create(['orders' => [...], 'orderIds' => [...], 'quoteIds' => [...]])`.
 *
 * @api
 */
interface EventOrdersInterface
{
    /**
     * Order objects the event carries, saved or still being placed.
     *
     * @return list<OrderInterface>
     */
    public function getOrders(): array;

    /**
     * Ids of saved orders the event names.
     *
     * @return list<int> Positive order entity ids
     */
    public function getOrderIds(): array;

    /**
     * Ids of carts the event carries: the order placed from such a cart is what the event is about, whether or not
     * it exists yet.
     *
     * @return list<int> Positive cart (quote) entity ids
     */
    public function getQuoteIds(): array;

    /**
     * Whether the reader found no order, and no cart of one, in the event.
     *
     * @return bool
     */
    public function isEmpty(): bool;
}
