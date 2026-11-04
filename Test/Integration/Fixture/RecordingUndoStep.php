<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\TestOrder\Test\Integration\Fixture;

use Hryvinskyi\TestOrder\Api\UndoStepInterface;
use Magento\Sales\Api\Data\OrderInterface;

/**
 * An undo step that takes nothing back and only notes the number of each order it ran for, contributed in place of
 * a real integration's step so that no integration is ever called from a test.
 */
class RecordingUndoStep implements UndoStepInterface
{
    /**
     * @var list<string> Order numbers, one per run
     */
    public array $undone = [];

    /**
     * @inheritDoc
     */
    public function undo(OrderInterface $order): void
    {
        $this->undone[] = (string)$order->getIncrementId();
    }
}
