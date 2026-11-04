<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\TestOrder\Test\Unit\Observer;

use Hryvinskyi\TestOrder\Api\UndoRunnerInterface;
use Hryvinskyi\TestOrder\Observer\UndoTestOrderOnCancel;
use Magento\Framework\Event;
use Magento\Framework\Event\Observer;
use Magento\Sales\Api\Data\OrderInterface;
use PHPUnit\Framework\TestCase;

/**
 * A cancelled order is handed to the undo runner, which decides whether it is a test order still to be undone.
 */
class UndoTestOrderOnCancelTest extends TestCase
{
    /**
     * The cancelled order goes to the runner.
     *
     * @return void
     */
    public function testTheCancelledOrderGoesToTheRunner(): void
    {
        $order = $this->createMock(OrderInterface::class);
        $runner = $this->createMock(UndoRunnerInterface::class);
        $runner->expects(self::once())->method('undo')->with($order)->willReturn(true);

        (new UndoTestOrderOnCancel($runner))->execute($this->observerOf(['order' => $order]));
    }

    /**
     * An event without an order asks nothing.
     *
     * @return void
     */
    public function testAnEventWithoutAnOrderAsksNothing(): void
    {
        $runner = $this->createMock(UndoRunnerInterface::class);
        $runner->expects(self::never())->method('undo');

        (new UndoTestOrderOnCancel($runner))->execute($this->observerOf(['order' => 'not an order']));
    }

    /**
     * An observer carrying an event with the data.
     *
     * @param array<string, mixed> $data Event data
     * @return Observer
     */
    private function observerOf(array $data): Observer
    {
        return new Observer(['event' => new Event($data)]);
    }
}
