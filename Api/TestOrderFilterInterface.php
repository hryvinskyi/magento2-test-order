<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\TestOrder\Api;

use Magento\Sales\Model\ResourceModel\Order\Collection as OrderCollection;

/**
 * Keeps test orders out of a selection of orders, for an integration that selects the orders it sends out instead of
 * reacting to an event.
 *
 * The condition is this module's: which table records test carts, and how an order is matched to it, stay here.
 *
 * @api
 */
interface TestOrderFilterInterface
{
    /**
     * Leave every test order out of the collection.
     *
     * Applying it again to the same collection changes nothing. An order that names no cart stays in.
     *
     * @param OrderCollection $orders Collection of `sales_order` rows, not yet loaded
     * @return void
     */
    public function excludeTestOrders(OrderCollection $orders): void;
}
