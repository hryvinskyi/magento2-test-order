<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\TestOrder\Api;

use Magento\Sales\Api\Data\OrderInterface;

/**
 * Runs every contributed undo step for a test order, once per committed claim, however many times and from wherever
 * it is asked.
 *
 * The claim and the steps run inside the cancellation's transaction. When that rolls back, the claim goes with it and
 * the steps run again on the next try, so a step must be safe to repeat, and must keep outside calls short, as it holds
 * the order row.
 */
interface UndoRunnerInterface
{
    /**
     * Undo what placing the order did, unless it is no test order, is not saved yet, or has already been undone.
     *
     * The right to undo is claimed for the order in one insert under a unique key before any step runs, so of any
     * number of concurrent callers one runs the steps, and a rolled-back claim lets the next try run them again. Every
     * order of a cart is claimed on its own.
     *
     * @param OrderInterface $order Saved order being cancelled or abandoned
     * @return bool True only for the call that ran the steps
     */
    public function undo(OrderInterface $order): bool;
}
