<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\TestOrder\Model;

use Hryvinskyi\TestOrder\Api\TestOrdersInterface;
use Hryvinskyi\TestOrder\Api\UndoClaimsInterface;
use Hryvinskyi\TestOrder\Api\UndoRunnerInterface;
use Hryvinskyi\TestOrder\Api\UndoStepInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Psr\Log\LoggerInterface;

/**
 * Runs the contributed undo steps for a test order once per committed claim: the claim on the order comes first, then
 * every step in the pool's order. Each order of a cart is undone on its own. A step that fails is logged with the order
 * and the step, and does not stop the others.
 *
 * During a cancellation the claim and the steps run inside its transaction: when that rolls back, the claim goes with
 * it and the steps run again on the next try. So a step must be safe to repeat, and keep outside calls short, as it
 * holds the order row.
 *
 * The pool (`steps`) is filled by the modules that know each integration; with no step contributed, undoing a test
 * order only records the claim.
 */
class UndoRunner implements UndoRunnerInterface
{
    /**
     * @var array<string, UndoStepInterface> Steps by name
     */
    private readonly array $steps;

    /**
     * @param TestOrdersInterface $testOrders Answers whether the order is a test order
     * @param UndoClaimsInterface $undoClaims The one right per order to undo it
     * @param LoggerInterface $logger Where a failing step is reported
     * @param array<mixed> $steps Undo steps by name, in the order they run
     * @throws \InvalidArgumentException When an item of the pool is not an undo step
     */
    public function __construct(
        private readonly TestOrdersInterface $testOrders,
        private readonly UndoClaimsInterface $undoClaims,
        private readonly LoggerInterface $logger,
        array $steps = []
    ) {
        $named = [];
        foreach ($steps as $name => $step) {
            if (!$step instanceof UndoStepInterface) {
                throw new \InvalidArgumentException(sprintf(
                    'The test-order undo step "%s" does not implement %s.',
                    $name,
                    UndoStepInterface::class
                ));
            }
            $named[(string)$name] = $step;
        }
        $this->steps = $named;
    }

    /**
     * @inheritDoc
     */
    public function undo(OrderInterface $order): bool
    {
        $orderId = (int)$order->getEntityId();
        if ($orderId <= 0 || !$this->testOrders->isTestOrder($order)) {
            return false;
        }

        if (!$this->undoClaims->claim($orderId)) {
            return false;
        }

        foreach ($this->steps as $name => $step) {
            $this->run($name, $step, $order);
        }

        return true;
    }

    /**
     * Run one step, reporting its failure instead of passing it on.
     *
     * @param string $name Step's name in the pool
     * @param UndoStepInterface $step Step
     * @param OrderInterface $order The test order
     * @return void
     */
    private function run(string $name, UndoStepInterface $step, OrderInterface $order): void
    {
        try {
            $step->undo($order);
        } catch (\Throwable $failure) {
            $this->logger->error(
                sprintf(
                    'Undoing test order %s (cart %d): the step "%s" failed and was not retried: %s',
                    (string)$order->getIncrementId(),
                    (int)$order->getQuoteId(),
                    $name,
                    $failure->getMessage()
                ),
                ['exception' => $failure]
            );
        }
    }
}
