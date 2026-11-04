<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\TestOrder\Api;

/**
 * The question asked about a cart before its order exists: is the end-to-end runner checking it out as a test order
 * the shop recognised?
 *
 * A cart is a test cart only when it was recognised with a valid proof; the order placed from it is then a test
 * order. The answer is read from the persisted record keyed by the cart, kept for the rest of the request, and
 * updated at once when a cart is recognised.
 *
 * @api
 */
interface TestCartsInterface
{
    /**
     * Whether the cart with this id was recognised as a test cart.
     *
     * @param int $quoteId Cart (quote) entity id
     * @return bool True for a test cart; false for an unknown or non-positive id
     */
    public function isTestCart(int $quoteId): bool;
}
