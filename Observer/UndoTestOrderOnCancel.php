<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\TestOrder\Observer;

use Hryvinskyi\TestOrder\Api\UndoRunnerInterface;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Sales\Api\Data\OrderInterface;

/**
 * Takes back what placing a test order did when the order is cancelled (`order_cancel_after`), whoever cancels it:
 * the runner, the clean-up job, a job that cancels pending orders, or a person in the admin.
 *
 * An ordinary order, and a test order already undone, are left to the runner to refuse.
 */
class UndoTestOrderOnCancel implements ObserverInterface
{
    /**
     * @param UndoRunnerInterface $undoRunner Runs the undo steps once per test order
     */
    public function __construct(
        private readonly UndoRunnerInterface $undoRunner
    ) {
    }

    /**
     * Undo the cancelled order when it is a test order.
     *
     * @param Observer $observer Carries the cancelled `order`
     * @return void
     */
    public function execute(Observer $observer): void
    {
        $order = $observer->getEvent()->getData('order');
        if (!$order instanceof OrderInterface) {
            return;
        }

        $this->undoRunner->undo($order);
    }
}
