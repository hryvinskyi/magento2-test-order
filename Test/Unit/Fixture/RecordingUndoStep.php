<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\TestOrder\Test\Unit\Fixture;

use Hryvinskyi\TestOrder\Api\UndoStepInterface;
use Magento\Sales\Api\Data\OrderInterface;

/**
 * An undo step that writes its name into a shared journal each time it runs, and fails when told to.
 */
class RecordingUndoStep implements UndoStepInterface
{
    /**
     * @param \ArrayObject<int, string> $journal Names of the steps run, in order
     * @param string $name This step's name
     * @param bool $fails Whether the step throws after writing its name
     */
    public function __construct(
        private readonly \ArrayObject $journal,
        private readonly string $name,
        private readonly bool $fails = false
    ) {
    }

    /**
     * @inheritDoc
     */
    public function undo(OrderInterface $order): void
    {
        $this->journal->append($this->name . ':' . (string)$order->getIncrementId());
        if ($this->fails) {
            throw new \RuntimeException('The integration did not answer.');
        }
    }
}
