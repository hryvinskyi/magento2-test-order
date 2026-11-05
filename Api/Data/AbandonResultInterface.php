<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\TestOrder\Api\Data;

/**
 * What abandoning a cart's test orders did: the number and state of an order left open when there is one, otherwise
 * of the newest order, and whether every order of the cart was deleted. On REST:
 * `{"increment_id"?, "state", "deleted"}`.
 *
 * Created through the generated `AbandonResultInterfaceFactory`:
 * `create(['incrementId' => …, 'state' => …, 'deleted' => …])`.
 *
 * @api
 */
interface AbandonResultInterface
{
    /**
     * The order's number.
     *
     * @return string|null Null when the order has none
     */
    public function getIncrementId(): ?string;

    /**
     * The order's state afterwards: `canceled` once it has been cancelled, `closed` once it has been refunded, and its
     * earlier state when it could be neither. The first two mean the order has ended.
     *
     * @return string
     */
    public function getState(): string;

    /**
     * Whether every order of the cart was deleted after it was cancelled. A closed order is never deleted.
     *
     * @return bool
     */
    public function isDeleted(): bool;
}
