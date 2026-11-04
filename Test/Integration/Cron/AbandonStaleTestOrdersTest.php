<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\TestOrder\Test\Integration\Cron;

use Hryvinskyi\TestOrder\Api\OrderDeletionInterface;
use Hryvinskyi\TestOrder\Api\TestCartRecordsInterface;
use Hryvinskyi\TestOrder\Api\TestOrderRepositoryInterface;
use Hryvinskyi\TestOrder\Api\UndoClaimsInterface;
use Hryvinskyi\TestOrder\Cron\AbandonStaleTestOrders;
use Hryvinskyi\TestOrder\Model\AbandonTestOrder;
use Hryvinskyi\TestOrder\Model\TestOrderRepository;
use Hryvinskyi\TestOrder\Test\Integration\OrderRows;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Exception\CouldNotDeleteException;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\TestFramework\Helper\Bootstrap;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The real clean-up job with the real repository and the real use case: a cancelled order whose delete failed, or that
 * someone else cancelled, is tried again on later runs, and ends in the critical line at five attempts.
 *
 * The orders are bare rows, cancelled already and claimed, as a first run leaves them after its delete failed, so no
 * cancellation runs and no integration listening to it is called. The record is aged in the table.
 *
 * @magentoDbIsolation enabled
 * @magentoAppIsolation enabled
 * @magentoAppArea crontab
 */
class AbandonStaleTestOrdersTest extends TestCase
{
    private const int QUOTE_ID = 987401;

    private const string LONG_AGO = '2026-01-01 00:00:00';

    /**
     * @var LoggerInterface&MockObject
     */
    private LoggerInterface&MockObject $logger;

    /**
     * @var list<string> Order numbers whose delete was asked for
     */
    private array $deletions = [];

    /**
     * @inheritDoc
     */
    protected function setUp(): void
    {
        $this->logger = $this->createMock(LoggerInterface::class);
    }

    /**
     * A delete that keeps failing is tried on five runs, each counted, ends in one critical line that names the cart
     * and its order, and is not tried on a sixth run.
     *
     * @magentoConfigFixture default/test_order/cleanup/delete_cancelled 1
     * @return void
     */
    public function testAFailedDeleteIsTriedAgainAndEndsInTheCriticalLine(): void
    {
        $this->cancelledAndClaimedOrder();
        $job = $this->job($this->failingDeletion());

        $this->logger->expects(self::exactly(4))->method('error')
            ->with(self::stringContains('cart ' . self::QUOTE_ID));
        $this->logger->expects(self::once())->method('critical')->with(
            self::logicalAnd(
                self::stringContains('cart ' . self::QUOTE_ID),
                self::stringContains('TESTORDER-' . self::QUOTE_ID),
                self::stringContains('after 5 attempts')
            )
        );

        for ($run = 1; $run <= AbandonStaleTestOrders::MAX_FAILED_ATTEMPTS + 1; $run++) {
            $job->execute();
        }

        self::assertCount(AbandonStaleTestOrders::MAX_FAILED_ATTEMPTS, $this->deletions);
        self::assertSame(
            AbandonStaleTestOrders::MAX_FAILED_ATTEMPTS,
            $this->repository()->getByQuoteId(self::QUOTE_ID)->getAbandonAttempts()
        );
    }

    /**
     * A test order someone else cancelled is claimed by that cancellation, and is still deleted by the clean-up.
     *
     * @magentoConfigFixture default/test_order/cleanup/delete_cancelled 1
     * @return void
     */
    public function testATestOrderSomeoneElseCancelledIsDeleted(): void
    {
        $orderId = $this->cancelledAndClaimedOrder();

        $this->job()->execute();

        self::assertSame(0, $this->orderCount($orderId));
    }

    /**
     * While nothing is deleted, a cancelled and claimed order leaves nothing for the clean-up to do.
     *
     * @magentoConfigFixture default/test_order/cleanup/delete_cancelled 0
     * @return void
     */
    public function testACancelledOrderIsLeftAloneWhileNothingIsDeleted(): void
    {
        $orderId = $this->cancelledAndClaimedOrder();
        $job = $this->job($this->failingDeletion());

        $job->execute();

        self::assertSame([], $this->deletions);
        self::assertSame(1, $this->orderCount($orderId));
    }

    /**
     * A recognised cart, recognised long ago, with a cancelled order whose undo claim is written.
     *
     * @return int Order entity id
     */
    private function cancelledAndClaimedOrder(): int
    {
        $this->records()->recognise(self::QUOTE_ID);
        $resource = $this->resource();
        $resource->getConnection()->update(
            $resource->getTableName(TestOrderRepository::TABLE_NAME),
            ['recognised_at' => self::LONG_AGO],
            ['quote_id = ?' => self::QUOTE_ID]
        );
        $orderId = OrderRows::insert(self::QUOTE_ID, '', 'canceled');
        $claims = Bootstrap::getObjectManager()->get(UndoClaimsInterface::class);
        self::assertInstanceOf(UndoClaimsInterface::class, $claims);
        $claims->claim($orderId);

        return $orderId;
    }

    /**
     * A deletion that notes the order and fails.
     *
     * @return OrderDeletionInterface
     */
    private function failingDeletion(): OrderDeletionInterface
    {
        $deletion = $this->createStub(OrderDeletionInterface::class);
        $deletion->method('delete')->willReturnCallback(function (OrderInterface $order): void {
            $this->deletions[] = (string)$order->getIncrementId();
            throw new CouldNotDeleteException(__('The order could not be deleted.'));
        });

        return $deletion;
    }

    /**
     * The job from the container's wiring, reporting to a recording logger.
     *
     * @param OrderDeletionInterface|null $deletion What deletes an order; the container's when null
     * @return AbandonStaleTestOrders
     */
    private function job(?OrderDeletionInterface $deletion = null): AbandonStaleTestOrders
    {
        $objectManager = Bootstrap::getObjectManager();
        $abandonTestOrder = $objectManager->create(
            AbandonTestOrder::class,
            $deletion === null ? [] : ['orderDeletion' => $deletion]
        );
        $job = $objectManager->create(
            AbandonStaleTestOrders::class,
            ['abandonTestOrder' => $abandonTestOrder, 'logger' => $this->logger]
        );
        self::assertInstanceOf(AbandonStaleTestOrders::class, $job);

        return $job;
    }

    /**
     * How many rows hold the order.
     *
     * @param int $orderId Order entity id
     * @return int
     */
    private function orderCount(int $orderId): int
    {
        $resource = $this->resource();
        $connection = $resource->getConnection();
        $count = $connection->fetchOne(
            $connection->select()
                ->from($resource->getTableName('sales_order'), ['COUNT(*)'])
                ->where('entity_id = ?', $orderId)
        );

        return is_numeric($count) ? (int)$count : -1;
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
