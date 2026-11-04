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
 * Takes back, in one integration, what placing a test order did there.
 *
 * Steps form an open pool (the `steps` argument of {@see \Hryvinskyi\TestOrder\Model\UndoRunner}): the module that
 * knows an integration contributes its step with a `di.xml` item, and this module never names it.
 *
 * Each step runs once per committed claim on a test order, when the order is cancelled — by whoever cancels it — or,
 * for an order that can no longer be cancelled, when it is abandoned. The claim and the steps run inside the
 * cancellation's transaction: when that rolls back the claim goes with it, and the steps run again on the next try. A
 * step must therefore be safe to repeat, and must keep outside calls short, as it holds the order row.
 *
 * A step that throws is not retried by the runner: a condition it expects (the thing to take back is already gone) is
 * not a failure and is handled inside the step. A step that throws is logged with the order and the step's name, and
 * the remaining steps still run.
 *
 * @api
 */
interface UndoStepInterface
{
    /**
     * Take back what placing the test order did in this step's integration.
     *
     * @param OrderInterface $order The test order, cancelled or past cancelling
     * @return void
     */
    public function undo(OrderInterface $order): void;
}
