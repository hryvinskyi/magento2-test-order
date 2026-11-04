<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\TestOrder\Api\Data;

/**
 * A cart the shop recognised as a test cart. Every order placed from it is a test order.
 *
 * Times are UTC, formatted `Y-m-d H:i:s`.
 */
interface TestOrderInterface
{
    public const ENTITY_ID = 'entity_id';
    public const QUOTE_ID = 'quote_id';
    public const ORDER_ID = 'order_id';
    public const RECOGNISED_AT = 'recognised_at';
    public const ABANDON_ATTEMPTS = 'abandon_attempts';

    /**
     * Record id.
     *
     * @return int
     */
    public function getEntityId(): int;

    /**
     * The recognised cart.
     *
     * @return int Cart (quote) entity id
     */
    public function getQuoteId(): int;

    /**
     * The order placed from the cart, once linked.
     *
     * @return int|null Order entity id; null until linked or once the linked order is deleted, which does not mean no
     *                  order exists: every order whose cart id is the record's is a test order
     */
    public function getOrderId(): ?int;

    /**
     * When the cart was last recognised.
     *
     * @return string UTC, `Y-m-d H:i:s`
     */
    public function getRecognisedAt(): string;

    /**
     * How many times the clean-up job tried to end the cart's test orders and failed.
     *
     * @return int Zero or more
     */
    public function getAbandonAttempts(): int;
}
