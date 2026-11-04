<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\TestOrder\Api;

/**
 * How the shop ends test orders: whether a cancelled one is deleted, and when one left behind is ended for the runner.
 */
interface CleanupConfigInterface
{
    /**
     * Minutes after recognition at which a test order is ended when nothing else is configured.
     */
    public const int DEFAULT_ABANDON_AFTER_MINUTES = 30;

    /**
     * Whether a test order is deleted once it has been cancelled.
     *
     * @return bool
     */
    public function deletesCancelledTestOrders(): bool;

    /**
     * How long after its cart was recognised a test order that is still not undone gets ended by the clean-up job.
     *
     * @return int Minutes, at least one
     */
    public function abandonAfterMinutes(): int;
}
