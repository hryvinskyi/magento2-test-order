<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\TestOrder\Test\Unit\Model;

use Hryvinskyi\TestOrder\Model\UndoRunner;
use Hryvinskyi\TestOrder\Test\Unit\Fixture\InMemoryTestOrders;
use Hryvinskyi\TestOrder\Test\Unit\Fixture\InMemoryUndoClaims;
use Hryvinskyi\TestOrder\Test\Unit\Fixture\RecordingUndoStep;
use Magento\Sales\Api\Data\OrderInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The undo steps run once per test order, in the pool's order, only for the caller that wins the order's claim; each
 * order of a cart is undone on its own; a failing step is reported and the others still run.
 */
class UndoRunnerTest extends TestCase
{
    private const int QUOTE_ID = 4711;

    private const int ORDER_ID = 31;

    private const string INCREMENT_ID = '100000042';

    /**
     * @var InMemoryUndoClaims
     */
    private InMemoryUndoClaims $undoClaims;

    /**
     * @var LoggerInterface&MockObject
     */
    private LoggerInterface&MockObject $logger;

    /**
     * @var \ArrayObject<int, string>
     */
    private \ArrayObject $journal;

    /**
     * @inheritDoc
     */
    protected function setUp(): void
    {
        $this->undoClaims = new InMemoryUndoClaims();
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->journal = new \ArrayObject();
    }

    /**
     * The caller that wins the claim runs every step once, in the pool's order.
     *
     * @return void
     */
    public function testTheWinnerOfTheClaimRunsEveryStepOnceInOrder(): void
    {
        $undone = $this->runner([
            'session' => new RecordingUndoStep($this->journal, 'session'),
            'feed' => new RecordingUndoStep($this->journal, 'feed'),
        ])->undo($this->order(self::ORDER_ID, self::INCREMENT_ID));

        self::assertTrue($undone);
        self::assertSame([self::ORDER_ID], $this->undoClaims->claimed);
        self::assertSame(
            ['session:' . self::INCREMENT_ID, 'feed:' . self::INCREMENT_ID],
            $this->journal->getArrayCopy()
        );
    }

    /**
     * Two orders placed from the same cart are each undone once: undoing the first does not use up the second.
     *
     * @return void
     */
    public function testEachOrderOfACartIsUndoneOnce(): void
    {
        $runner = $this->runner(['session' => new RecordingUndoStep($this->journal, 'session')]);
        $first = $this->order(self::ORDER_ID, self::INCREMENT_ID);
        $second = $this->order(self::ORDER_ID + 1, '100000043');

        self::assertTrue($runner->undo($first));
        self::assertTrue($runner->undo($second));
        self::assertFalse($runner->undo($first));
        self::assertFalse($runner->undo($second));
        self::assertSame(['session:100000042', 'session:100000043'], $this->journal->getArrayCopy());
    }

    /**
     * A step that fails is reported with the order and the step, and the steps after it still run.
     *
     * @return void
     */
    public function testAFailingStepIsReportedAndDoesNotStopTheOthers(): void
    {
        $this->logger->expects(self::once())->method('error')->with(
            self::logicalAnd(
                self::stringContains(self::INCREMENT_ID),
                self::stringContains('"session"'),
                self::stringContains('The integration did not answer.')
            )
        );

        $undone = $this->runner([
            'session' => new RecordingUndoStep($this->journal, 'session', fails: true),
            'feed' => new RecordingUndoStep($this->journal, 'feed'),
        ])->undo($this->order(self::ORDER_ID, self::INCREMENT_ID));

        self::assertTrue($undone);
        self::assertSame(
            ['session:' . self::INCREMENT_ID, 'feed:' . self::INCREMENT_ID],
            $this->journal->getArrayCopy()
        );
    }

    /**
     * A caller that loses the claim — the order was already undone — runs nothing.
     *
     * @return void
     */
    public function testALostClaimRunsNothing(): void
    {
        $this->undoClaims->claim(self::ORDER_ID);

        $undone = $this->runner(['session' => new RecordingUndoStep($this->journal, 'session')])
            ->undo($this->order(self::ORDER_ID, self::INCREMENT_ID));

        self::assertFalse($undone);
        self::assertSame([], $this->journal->getArrayCopy());
    }

    /**
     * An order whose cart was never recognised is no test order: nothing is claimed and nothing runs.
     *
     * @return void
     */
    public function testAnOrdinaryOrderIsLeftAlone(): void
    {
        $undone = $this->runner(['session' => new RecordingUndoStep($this->journal, 'session')])
            ->undo($this->order(self::ORDER_ID, self::INCREMENT_ID, self::QUOTE_ID + 1));

        self::assertFalse($undone);
        self::assertSame([], $this->undoClaims->claimed);
        self::assertSame([], $this->journal->getArrayCopy());
    }

    /**
     * An order that is not saved yet has nothing to claim, and nothing runs.
     *
     * @return void
     */
    public function testAnUnsavedOrderIsNotClaimed(): void
    {
        self::assertFalse(
            $this->runner(['session' => new RecordingUndoStep($this->journal, 'session')])
                ->undo($this->order(null, self::INCREMENT_ID))
        );
        self::assertSame([], $this->undoClaims->claimed);
        self::assertSame([], $this->journal->getArrayCopy());
    }

    /**
     * With no step contributed, undoing still claims the order and reports that it did.
     *
     * @return void
     */
    public function testWithoutStepsTheOrderIsStillClaimed(): void
    {
        self::assertTrue($this->runner([])->undo($this->order(self::ORDER_ID, self::INCREMENT_ID)));
        self::assertSame([self::ORDER_ID], $this->undoClaims->claimed);
    }

    /**
     * Something in the pool that is not an undo step is refused at once, naming the item.
     *
     * @return void
     */
    public function testAPoolItemThatIsNoStepIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('"stray"');

        $this->runner(['stray' => new \stdClass()]);
    }

    /**
     * The runner with the pool, for a shop where only the cart {@see self::QUOTE_ID} was recognised.
     *
     * @param array<mixed> $steps Pool, by name
     * @return UndoRunner
     */
    private function runner(array $steps): UndoRunner
    {
        return new UndoRunner(new InMemoryTestOrders([self::QUOTE_ID]), $this->undoClaims, $this->logger, $steps);
    }

    /**
     * An order placed from a cart.
     *
     * @param int|null $orderId Order entity id; null for an order not saved yet
     * @param string $incrementId Order number
     * @param int $quoteId Cart the order was placed from
     * @return OrderInterface
     */
    private function order(?int $orderId, string $incrementId, int $quoteId = self::QUOTE_ID): OrderInterface
    {
        $order = $this->createMock(OrderInterface::class);
        $order->method('getEntityId')->willReturn($orderId);
        $order->method('getQuoteId')->willReturn($quoteId);
        $order->method('getIncrementId')->willReturn($incrementId);

        return $order;
    }
}
