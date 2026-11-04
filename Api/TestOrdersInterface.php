<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\TestOrder\Api;

use Magento\Sales\Api\Data\OrderInterface;

/**
 * The one question an integration asks before it acts on an order: did the end-to-end runner place it as a test
 * order the shop recognised?
 *
 * An order is a test order only when its cart was recognised with a valid proof. The answer is read from the
 * persisted record keyed by the cart, so it holds wherever the order is met: while it is being placed and before
 * it has an id, on the payment provider's return, in a webhook, a scheduled job or a cancellation.
 *
 * Answers are kept for the rest of the request; recognising a cart updates the kept answer at once. An application
 * server forgets them between requests. A queue consumer has no requests and keeps them for the worker's life, so one
 * that asks about a cart before it is placed keeps that "no" until it restarts.
 *
 * @api
 */
interface TestOrdersInterface
{
    /**
     * Whether the order was placed from a recognised cart.
     *
     * Only the order's cart id is read, so an order still being placed (no entity id yet) is answered too. An order
     * that names no cart is never a test order.
     *
     * @param OrderInterface $order Order being asked about, saved or not
     * @return bool True for a test order
     */
    public function isTestOrder(OrderInterface $order): bool;

    /**
     * Whether the saved order with this id was placed from a recognised cart.
     *
     * For readers that hold only an order id, such as an order address or a status-history entry.
     *
     * @param int $orderId Order entity id
     * @return bool True for a test order; false for an unknown id
     */
    public function isTestOrderId(int $orderId): bool;
}
