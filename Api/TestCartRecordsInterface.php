<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\TestOrder\Api;

/**
 * Writes the record of a cart while its order is being placed: the cart is recognised, its order is noted, or a failed
 * placement forgets it again.
 */
interface TestCartRecordsInterface
{
    /**
     * Record the cart as a test cart, now.
     *
     * A cart that is already recorded keeps its record and only gets the new recognition time, so recognising the
     * same cart again (a placement retried after a refusal) never fails on the cart's uniqueness.
     *
     * @param int $quoteId Cart (quote) entity id
     * @return void
     */
    public function recognise(int $quoteId): void;

    /**
     * Note which order was placed from a recognised cart.
     *
     * A convenience for readers that start from the record: the order of a record that was never linked is still
     * found through its cart id. A cart that was never recognised is left alone.
     *
     * @param int $quoteId Cart (quote) entity id
     * @param int $orderId Order entity id
     * @return void
     */
    public function linkOrder(int $quoteId, int $orderId): void;

    /**
     * Forget a recognised cart from which no order exists, after its placement failed.
     *
     * One conditional delete: a record whose cart has an order — one that placement saved before it failed, or an
     * earlier order of the same cart — is kept, because that order is a test order for as long as it exists.
     *
     * @param int $quoteId Cart (quote) entity id
     * @return bool True when the record was removed
     */
    public function forgetOrderless(int $quoteId): bool;
}
