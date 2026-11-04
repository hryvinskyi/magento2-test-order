<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\TestOrder\Api;

/**
 * The one right, per test order, to take back what placing it did.
 *
 * Kept per order, not per cart: a cart can carry several orders (a placement saved before it failed, then a retry),
 * and each of them is undone once per committed claim. A claim made inside a transaction that rolls back is gone with
 * it, so the next try claims again.
 */
interface UndoClaimsInterface
{
    /**
     * Claim the right to undo the saved order.
     *
     * The claim and the stamp of when it was undone are one insert under a unique key, never a read followed by a
     * write, so of any number of concurrent callers exactly one is told true; the others, and every later caller, are
     * told false and must undo nothing.
     *
     * @param int $orderId Entity id of a saved order
     * @return bool True only for the caller that won the claim
     */
    public function claim(int $orderId): bool;
}
