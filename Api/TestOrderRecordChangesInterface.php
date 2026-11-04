<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\TestOrder\Api;

/**
 * What the record writer tells the kept answers, so they stay true after a write.
 *
 * Internal to this module. Written to the same memory {@see KeptTestOrderAnswersInterface} reads.
 */
interface TestOrderRecordChangesInterface
{
    /**
     * A cart has just been recorded as a test cart.
     *
     * Its answer becomes yes, and every "no" kept for an order is forgotten: such an order may have been placed from
     * this cart, and the kept answers per order do not say which cart they came from.
     *
     * @param int $quoteId Cart (quote) entity id
     * @return void
     */
    public function recognised(int $quoteId): void;

    /**
     * A cart's record has just been removed: the cart is no test cart any more.
     *
     * No order needs forgetting: a record is only removed while no order was placed from its cart.
     *
     * @param int $quoteId Cart (quote) entity id
     * @return void
     */
    public function forgotten(int $quoteId): void;

    /**
     * The order placed from a recorded test cart has just been linked to its record.
     *
     * @param int $quoteId Cart (quote) entity id
     * @param int $orderId Order entity id
     * @return void
     */
    public function linked(int $quoteId, int $orderId): void;
}
