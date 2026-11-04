<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\TestOrder\Api;

/**
 * What the clean-up job asks of the records: which carts it still has work for, how often it failed on one, and which
 * records have outlived their purpose.
 */
interface StaleTestCartsInterface
{
    /**
     * Carts with an order the clean-up job still has something to do for, a while after the cart was recognised.
     *
     * A cart is named while any order placed from it is not undone. While cancelled orders are still to be deleted, it
     * is also named while any of its orders is cancelled and still exists: the delete failed, or someone else cancelled
     * the order. An order past cancelling does not count: nothing more can be done for it, and it would hold a place
     * in the batch for ever. A cart is named only until the clean-up job has failed to end its orders the given number
     * of times: such a cart is left for a person. Longest-waiting first.
     *
     * @param int $minutes How long ago, at least, the cart was last recognised
     * @param int $maxFailedAttempts Failed attempts after which a cart is no longer named
     * @param int $limit Most carts to name
     * @param bool $deletesCancelled Whether cancelled orders are still to be deleted
     * @return list<int> Cart (quote) entity ids
     */
    public function getStaleQuoteIds(int $minutes, int $maxFailedAttempts, int $limit, bool $deletesCancelled): array;

    /**
     * Count one more failed attempt of the clean-up job to end the cart's test orders.
     *
     * The count is raised in one update, never read and written back.
     *
     * @param int $quoteId Cart (quote) entity id
     * @return int Failed attempts so far, this one included; zero when the cart was never recognised
     */
    public function recordFailedAbandon(int $quoteId): int;

    /**
     * Remove the records of carts from which no order exists, recognised longer ago than given.
     *
     * A record is removed only when no order names its cart: an order whose placement saved it but never succeeded
     * is still a test order, and keeps its record for as long as it exists.
     *
     * @param int $minutes How long ago, at least, the cart was last recognised
     * @return int Records removed
     */
    public function removeOrderless(int $minutes): int;
}
