<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\TestOrder\Api;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;

/**
 * A placement of a cart that honours the proof sent with the request being served.
 *
 * The module's own plugin on {@see \Magento\Quote\Api\CartManagementInterface::placeOrder()} is one caller. A checkout
 * that places some other way, or that answers now and places later from a request with no proof, wraps its placement
 * in this call at the point where the proof arrives. The cart stays recorded after the call, so the order placed from
 * it later is a test order and gets linked to the record as any other.
 *
 * Calls may nest on one cart, as when such a checkout wraps a placement that goes through the plugin: the record
 * belongs to the outer call, which is the one to forget it.
 *
 * @api
 */
interface TestCartPlacementInterface
{
    /**
     * Run a placement of the cart, as a test order when a proof is sent with the request.
     *
     * - No proof sent: the placement runs and nothing about the cart is looked up, so an ordinary placement costs
     *   nothing and an invalid id still meets the placement's own error.
     * - A proof sent: the placement runs only as a test order, or is refused.
     * - A placement that fails forgets only a record this call made, and only while no order of the cart exists. A
     *   record that was already there is another placement's, perhaps one still running, and stays. A record that
     *   cannot be forgotten is logged: the placement's own failure is what the caller sees.
     *
     * @template T
     * @param int|string $cartId The cart's entity id, or the masked id a cart is addressed by; an id of digits alone is
     *                           an entity id
     * @param callable(): T $placement Whatever turns the cart into an order, now or later
     * @param string|null $paymentMethod The method the placement names, for the cart guards; null when it names none
     *                                   yet, and then the method saved on the cart is what they see
     * @return T Whatever the placement returned
     * @throws LocalizedException When a proof is sent and cannot be honoured, which an entity id of no cart cannot be;
     *                            the placement was not called
     * @throws NoSuchEntityException When a proof is sent and a masked id resolves to no cart; the placement was not
     *                               called
     * @throws \Throwable Whatever the placement threw, unchanged
     */
    public function place(int|string $cartId, callable $placement, ?string $paymentMethod = null): mixed;
}
