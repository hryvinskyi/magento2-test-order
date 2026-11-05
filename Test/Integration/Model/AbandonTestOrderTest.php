<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\TestOrder\Test\Integration\Model;

use Hryvinskyi\TestOrder\Api\AbandonTestOrderInterface;
use Hryvinskyi\TestOrder\Api\PaidTestOrderRefundInterface;
use Hryvinskyi\TestOrder\Api\TestCartRecordsInterface;
use Hryvinskyi\TestOrder\Api\TestOrderRepositoryInterface;
use Hryvinskyi\TestOrder\Model\AbandonTestOrder;
use Hryvinskyi\TestOrder\Model\UndoRunner;
use Hryvinskyi\TestOrder\Test\Integration\Fixture\RecordingUndoStep;
use Hryvinskyi\TestOrder\Test\Integration\OrderRows;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\TestFramework\Helper\Bootstrap;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Abandoning a test order through the container's own wiring, against real order rows and the real record.
 *
 * The orders are bare rows that can no longer be cancelled (cancelled already, or complete), so no cancellation
 * runs and no integration listening to it is ever called; the undo runner gets a recording step in place of the
 * real ones. A bare row has no payment to refund, so the refund refuses here; a real paid order is ended in
 * {@see AbandonPaidTestOrderTest}.
 *
 * @magentoDbIsolation enabled
 * @magentoAppIsolation enabled
 * @magentoAppArea crontab
 */
class AbandonTestOrderTest extends TestCase
{
    private const int QUOTE_ID = 987101;

    /**
     * @var RecordingUndoStep
     */
    private RecordingUndoStep $step;

    /**
     * @inheritDoc
     */
    protected function setUp(): void
    {
        $this->step = new RecordingUndoStep();
    }

    /**
     * The container hands out this module's use case.
     *
     * @return void
     */
    public function testTheContainerHandsOutTheUseCase(): void
    {
        self::assertInstanceOf(
            AbandonTestOrder::class,
            Bootstrap::getObjectManager()->get(AbandonTestOrderInterface::class)
        );
    }

    /**
     * A cancelled test order is undone and, while deletion is on, deleted; the cart's record stays, unlinked, until
     * the clean-up job removes records without orders.
     *
     * @magentoConfigFixture default/test_order/cleanup/delete_cancelled 1
     * @return void
     */
    public function testACancelledTestOrderIsUndoneAndDeletedWhenDeletionIsOn(): void
    {
        $orderId = $this->testOrder('canceled', linked: true);

        $result = $this->abandonTestOrder()->abandon(self::QUOTE_ID);

        self::assertSame('canceled', $result->getState());
        self::assertTrue($result->isDeleted());
        self::assertSame('TESTORDER-' . self::QUOTE_ID, $result->getIncrementId());
        self::assertSame(['TESTORDER-' . self::QUOTE_ID], $this->step->undone);
        self::assertFalse($this->orderExists($orderId));
        self::assertNull($this->repository()->getByQuoteId(self::QUOTE_ID)->getOrderId());
    }

    /**
     * Two orders placed from one cart — one a placement saved before it failed, the other the retry — are both undone
     * and deleted; the answer names the newest and says deleted, since both are gone.
     *
     * @magentoConfigFixture default/test_order/cleanup/delete_cancelled 1
     * @return void
     */
    public function testEveryOrderOfTheCartIsEnded(): void
    {
        $older = $this->testOrder('canceled', linked: false, suffix: '-a');
        $newer = $this->testOrder('canceled', linked: true, suffix: '-b');

        $result = $this->abandonTestOrder()->abandon(self::QUOTE_ID);

        self::assertSame('TESTORDER-' . self::QUOTE_ID . '-b', $result->getIncrementId());
        self::assertTrue($result->isDeleted());
        self::assertSame(
            ['TESTORDER-' . self::QUOTE_ID . '-a', 'TESTORDER-' . self::QUOTE_ID . '-b'],
            $this->step->undone
        );
        self::assertFalse($this->orderExists($older));
        self::assertFalse($this->orderExists($newer));
    }

    /**
     * When one order of the cart can no longer be cancelled, it is undone and kept, the other is deleted, and the
     * answer does not claim the cart's orders are gone.
     *
     * @magentoConfigFixture default/test_order/cleanup/delete_cancelled 1
     * @return void
     */
    public function testTheAnswerIsDeletedOnlyWhenEveryOrderOfTheCartWasDeleted(): void
    {
        $kept = $this->testOrder('complete', linked: false, suffix: '-a');
        $deleted = $this->testOrder('canceled', linked: false, suffix: '-b');

        $result = $this->abandonTestOrder()->abandon(self::QUOTE_ID);

        self::assertFalse($result->isDeleted());
        self::assertTrue($this->orderExists($kept));
        self::assertFalse($this->orderExists($deleted));
        self::assertCount(2, $this->step->undone);
    }

    /**
     * While deletion is off, the cancelled test order is undone once, however often it is abandoned, and kept.
     *
     * @magentoConfigFixture default/test_order/cleanup/delete_cancelled 0
     * @return void
     */
    public function testACancelledTestOrderIsKeptWhenDeletionIsOff(): void
    {
        $orderId = $this->testOrder('canceled', linked: true);

        $result = $this->abandonTestOrder()->abandon(self::QUOTE_ID);
        $this->abandonTestOrder()->abandon(self::QUOTE_ID);

        self::assertFalse($result->isDeleted());
        self::assertTrue($this->orderExists($orderId));
        self::assertSame(['TESTORDER-' . self::QUOTE_ID], $this->step->undone);
    }

    /**
     * A test order that can neither be cancelled nor refunded is undone exactly once however often it is abandoned,
     * keeps its state, and is never deleted.
     *
     * @magentoConfigFixture default/test_order/cleanup/delete_cancelled 1
     * @return void
     */
    public function testAnOrderPastCancellingIsUndoneOnceAndKept(): void
    {
        $orderId = $this->testOrder('complete', linked: false);

        $first = $this->abandonTestOrder()->abandon(self::QUOTE_ID);
        $second = $this->abandonTestOrder()->abandon(self::QUOTE_ID);

        self::assertSame(['complete', false], [$first->getState(), $first->isDeleted()]);
        self::assertSame(['complete', false], [$second->getState(), $second->isDeleted()]);
        self::assertSame(['TESTORDER-' . self::QUOTE_ID], $this->step->undone);
        self::assertTrue($this->orderExists($orderId));
    }

    /**
     * A test order that can neither be cancelled nor refunded is reported for a person, with its number and state.
     *
     * @magentoConfigFixture default/test_order/cleanup/delete_cancelled 1
     * @return void
     */
    public function testAnOrderPastCancellingIsReportedForAPerson(): void
    {
        $this->testOrder('complete', linked: false);
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning')->with(
            self::logicalAnd(
                self::stringContains('TESTORDER-' . self::QUOTE_ID),
                self::stringContains('complete'),
                self::stringContains('person')
            )
        );
        $abandonTestOrder = Bootstrap::getObjectManager()->create(AbandonTestOrder::class, [
            'paidTestOrderRefund' => $this->refusingRefund(),
            'logger' => $logger,
        ]);
        self::assertInstanceOf(AbandonTestOrderInterface::class, $abandonTestOrder);

        $abandonTestOrder->abandon(self::QUOTE_ID);
    }

    /**
     * A cart that was never recognised cannot be abandoned.
     *
     * @return void
     */
    public function testACartNeverRecognisedCannotBeAbandoned(): void
    {
        OrderRows::insert(self::QUOTE_ID);

        $this->expectException(NoSuchEntityException::class);

        $this->abandonTestOrder()->abandon(self::QUOTE_ID);
    }

    /**
     * A recognised cart from which no order exists cannot be abandoned.
     *
     * @return void
     */
    public function testARecognisedCartWithoutAnOrderCannotBeAbandoned(): void
    {
        $this->records()->recognise(self::QUOTE_ID);

        $this->expectException(NoSuchEntityException::class);
        $this->expectExceptionMessage('No order was placed from test cart ' . self::QUOTE_ID . '.');

        $this->abandonTestOrder()->abandon(self::QUOTE_ID);
    }

    /**
     * A recognised cart with an order row in the state given.
     *
     * @param string $state Order state
     * @param bool $linked Whether the order is linked to the record, or only names the cart
     * @param string $suffix Tells apart several orders of the cart
     * @return int Order entity id
     */
    private function testOrder(string $state, bool $linked, string $suffix = ''): int
    {
        $this->records()->recognise(self::QUOTE_ID);
        $orderId = OrderRows::insert(self::QUOTE_ID, $suffix);
        $resource = $this->resource();
        $resource->getConnection()->update(
            $resource->getTableName('sales_order'),
            ['state' => $state, 'status' => $state],
            ['entity_id = ?' => $orderId]
        );
        if ($linked) {
            $this->records()->linkOrder(self::QUOTE_ID, $orderId);
        }

        return $orderId;
    }

    /**
     * The use case from the container's wiring, with a runner whose only step records and a refund that refuses.
     *
     * @return AbandonTestOrderInterface
     */
    private function abandonTestOrder(): AbandonTestOrderInterface
    {
        $objectManager = Bootstrap::getObjectManager();
        $runner = $objectManager->create(UndoRunner::class, ['steps' => ['recording' => $this->step]]);
        $abandonTestOrder = $objectManager->create(AbandonTestOrder::class, [
            'undoRunner' => $runner,
            'paidTestOrderRefund' => $this->refusingRefund(),
        ]);
        self::assertInstanceOf(AbandonTestOrderInterface::class, $abandonTestOrder);

        return $abandonTestOrder;
    }

    /**
     * A refund that refuses, which answers the order it was given.
     *
     * @return PaidTestOrderRefundInterface
     */
    private function refusingRefund(): PaidTestOrderRefundInterface
    {
        $refund = $this->createStub(PaidTestOrderRefundInterface::class);
        $refund->method('refund')->willReturnArgument(0);

        return $refund;
    }

    /**
     * Whether the order row exists.
     *
     * @param int $orderId Order entity id
     * @return bool
     */
    private function orderExists(int $orderId): bool
    {
        $resource = $this->resource();
        $connection = $resource->getConnection();

        return is_numeric($connection->fetchOne(
            $connection->select()
                ->from($resource->getTableName('sales_order'), ['entity_id'])
                ->where('entity_id = ?', $orderId)
        ));
    }

    /**
     * The container's record repository.
     *
     * @return TestOrderRepositoryInterface
     */
    private function repository(): TestOrderRepositoryInterface
    {
        $repository = Bootstrap::getObjectManager()->get(TestOrderRepositoryInterface::class);
        self::assertInstanceOf(TestOrderRepositoryInterface::class, $repository);

        return $repository;
    }

    /**
     * The container's record writer.
     *
     * @return TestCartRecordsInterface
     */
    private function records(): TestCartRecordsInterface
    {
        $records = Bootstrap::getObjectManager()->get(TestCartRecordsInterface::class);
        self::assertInstanceOf(TestCartRecordsInterface::class, $records);

        return $records;
    }

    /**
     * The installation's database resource.
     *
     * @return ResourceConnection
     */
    private function resource(): ResourceConnection
    {
        $resource = Bootstrap::getObjectManager()->get(ResourceConnection::class);
        self::assertInstanceOf(ResourceConnection::class, $resource);

        return $resource;
    }
}
