<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\TestOrder\Api;

/**
 * The answers to "is this a test order?" already given in this request, for those who ask.
 *
 * Internal to this module. It answers for the same memory as {@see TestOrderRecordChangesInterface}: a cart recognised
 * a moment ago is answered as a test cart only because the record writer and whoever asks share one instance.
 */
interface KeptTestOrderAnswersInterface
{
    /**
     * The kept answer for a cart.
     *
     * @param int $quoteId Cart (quote) entity id
     * @return bool|null Null when the cart has not been asked about or written in this request
     */
    public function forQuote(int $quoteId): ?bool;

    /**
     * Keep the answer for a cart.
     *
     * @param int $quoteId Cart (quote) entity id
     * @param bool $isTestOrder Answer
     * @return void
     */
    public function rememberQuote(int $quoteId, bool $isTestOrder): void;

    /**
     * The kept answer for an order.
     *
     * @param int $orderId Order entity id
     * @return bool|null Null when the order has not been asked about or written in this request
     */
    public function forOrder(int $orderId): ?bool;

    /**
     * Keep the answer for an order.
     *
     * @param int $orderId Order entity id
     * @param bool $isTestOrder Answer
     * @return void
     */
    public function rememberOrder(int $orderId, bool $isTestOrder): void;
}
