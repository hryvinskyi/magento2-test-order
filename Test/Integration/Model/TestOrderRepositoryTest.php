<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\TestOrder\Test\Integration\Model;

use Hryvinskyi\TestOrder\Api\StaleTestCartsInterface;
use Hryvinskyi\TestOrder\Api\TestCartRecordsInterface;
use Hryvinskyi\TestOrder\Api\TestOrderRepositoryInterface;
use Hryvinskyi\TestOrder\Api\UndoClaimsInterface;
use Hryvinskyi\TestOrder\Model\TestOrderRepository;
use Hryvinskyi\TestOrder\Test\Integration\OrderRows;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Magento\TestFramework\Helper\Bootstrap;
use PHPUnit\Framework\TestCase;

/**
 * The test-cart records against the real tables: recognising twice keeps one record, an order is linked to it and
 * deleting the order unlinks it without forgetting the cart, a failed placement forgets only an orderless cart, failed
 * abandon attempts are counted, stale carts are those with an order not undone and not given up, and orderless
 * records are removed.
 *
 * @magentoDbIsolation enabled
 * @magentoAppArea global
 */
class TestOrderRepositoryTest extends TestCase
{
    private const int QUOTE_ID = 987001;

    /**
     * The container hands out this module's repository.
     *
     * @return void
     */
    public function testTheContainerHandsOutTheRepository(): void
    {
        $objectManager = Bootstrap::getObjectManager();
        $repository = $objectManager->get(TestOrderRepositoryInterface::class);

        self::assertInstanceOf(TestOrderRepository::class, $repository);
        self::assertSame($repository, $objectManager->get(TestCartRecordsInterface::class));
        self::assertSame($repository, $objectManager->get(StaleTestCartsInterface::class));
    }

    /**
     * A recognised cart is recorded with the time it was recognised, unlinked and never tried by the clean-up job.
     *
     * @return void
     */
    public function testRecognisingACartRecordsIt(): void
    {
        $repository = $this->repositoryAt('2026-10-02 10:00:00');
        $repository->recognise(self::QUOTE_ID);
        $record = $repository->getByQuoteId(self::QUOTE_ID);

        self::assertGreaterThan(0, $record->getEntityId());
        self::assertSame(self::QUOTE_ID, $record->getQuoteId());
        self::assertSame('2026-10-02 10:00:00', $record->getRecognisedAt());
        self::assertNull($record->getOrderId());
        self::assertSame(0, $record->getAbandonAttempts());
        self::assertEquals($record, $this->repositoryAt('2026-10-02 11:00:00')->getByQuoteId(self::QUOTE_ID));
    }

    /**
     * Recognising the same cart again keeps its one record and only moves the recognition time.
     *
     * @return void
     */
    public function testRecognisingACartAgainKeepsItsRecord(): void
    {
        $repository = $this->repositoryAt('2026-10-02 10:00:00');
        $repository->recognise(self::QUOTE_ID);
        $first = $repository->getByQuoteId(self::QUOTE_ID);
        $repository = $this->repositoryAt('2026-10-02 10:07:30');
        $repository->recognise(self::QUOTE_ID);
        $second = $repository->getByQuoteId(self::QUOTE_ID);

        self::assertSame($first->getEntityId(), $second->getEntityId());
        self::assertSame('2026-10-02 10:07:30', $second->getRecognisedAt());
        self::assertSame(1, $this->recordCount(self::QUOTE_ID));
    }

    /**
     * A cart that was never recognised has no record.
     *
     * @return void
     */
    public function testACartNeverRecognisedHasNoRecord(): void
    {
        $this->expectException(NoSuchEntityException::class);

        $this->repositoryAt('2026-10-02 10:00:00')->getByQuoteId(self::QUOTE_ID);
    }

    /**
     * The order placed from a recognised cart is linked to its record; deleting that order unlinks it but keeps the
     * record, so that another order of the same cart is still a test order.
     *
     * @return void
     */
    public function testDeletingTheLinkedOrderKeepsTheRecord(): void
    {
        $repository = $this->repositoryAt('2026-10-02 10:00:00');
        $repository->recognise(self::QUOTE_ID);
        $orderId = OrderRows::insert(self::QUOTE_ID);

        $repository->linkOrder(self::QUOTE_ID, $orderId);

        self::assertSame($orderId, $repository->getByQuoteId(self::QUOTE_ID)->getOrderId());

        OrderRows::delete($orderId);

        self::assertSame(1, $this->recordCount(self::QUOTE_ID));
        self::assertNull($repository->getByQuoteId(self::QUOTE_ID)->getOrderId());
    }

    /**
     * Linking an order to a cart that was never recognised records nothing.
     *
     * @return void
     */
    public function testLinkingToAnUnrecognisedCartRecordsNothing(): void
    {
        $orderId = OrderRows::insert(self::QUOTE_ID);

        $this->repositoryAt('2026-10-02 10:00:00')->linkOrder(self::QUOTE_ID, $orderId);

        self::assertSame(0, $this->recordCount(self::QUOTE_ID));
    }

    /**
     * After a failed placement a cart from which no order exists is forgotten; one whose placement saved an order
     * before failing, or that has an earlier order, keeps its record.
     *
     * @return void
     */
    public function testAFailedPlacementForgetsOnlyAnOrderlessCart(): void
    {
        $orderless = self::QUOTE_ID;
        $withOrder = self::QUOTE_ID + 1;
        $repository = $this->repositoryAt('2026-10-02 10:00:00');
        $repository->recognise($orderless);
        $repository->recognise($withOrder);
        OrderRows::insert($withOrder);

        self::assertTrue($repository->forgetOrderless($orderless));
        self::assertFalse($repository->forgetOrderless($withOrder));
        self::assertFalse($repository->forgetOrderless(self::QUOTE_ID + 2));

        self::assertSame(0, $this->recordCount($orderless));
        self::assertSame(1, $this->recordCount($withOrder));
    }

    /**
     * Each failed abandon attempt raises the cart's count by one and reports the new count; a cart that was never
     * recognised counts nothing.
     *
     * @return void
     */
    public function testFailedAbandonAttemptsAreCounted(): void
    {
        $repository = $this->repositoryAt('2026-10-02 10:00:00');
        $repository->recognise(self::QUOTE_ID);

        self::assertSame(1, $repository->recordFailedAbandon(self::QUOTE_ID));
        self::assertSame(2, $repository->recordFailedAbandon(self::QUOTE_ID));
        self::assertSame(2, $repository->getByQuoteId(self::QUOTE_ID)->getAbandonAttempts());
        self::assertSame(0, $repository->recordFailedAbandon(self::QUOTE_ID + 1));
    }

    /**
     * Carts with an order left undone longer than asked are named, longest-waiting first and up to the limit — whether
     * their order is linked or only names the cart, and while any one of several orders is not undone. A cart too
     * recent, whose every order is undone, without any order, or given up after too many failed attempts is not.
     *
     * @return void
     */
    public function testStaleCartsAreThoseWithAnOrderLeftUndone(): void
    {
        $linked = self::QUOTE_ID;
        $unlinked = self::QUOTE_ID + 1;
        $recent = self::QUOTE_ID + 2;
        $undone = self::QUOTE_ID + 3;
        $orderless = self::QUOTE_ID + 4;
        $halfUndone = self::QUOTE_ID + 5;
        $givenUp = self::QUOTE_ID + 6;

        $this->repositoryAt('2026-10-02 08:30:00')->recognise($halfUndone);
        $this->repositoryAt('2026-10-02 09:00:00')->recognise($unlinked);
        $this->repositoryAt('2026-10-02 09:10:00')->recognise($linked);
        $this->repositoryAt('2026-10-02 09:20:00')->recognise($orderless);
        $this->repositoryAt('2026-10-02 09:50:00')->recognise($recent);
        $this->repositoryAt('2026-10-02 08:00:00')->recognise($undone);
        $this->repositoryAt('2026-10-02 08:00:00')->recognise($givenUp);

        $repository = $this->repositoryAt('2026-10-02 10:00:00');
        $repository->linkOrder($linked, OrderRows::insert($linked));
        OrderRows::insert($unlinked);
        OrderRows::insert($recent);
        $undoneOrder = OrderRows::insert($undone);
        $repository->linkOrder($undone, $undoneOrder);
        $this->undoClaims()->claim($undoneOrder);
        $this->undoClaims()->claim(OrderRows::insert($halfUndone, '-a'));
        OrderRows::insert($halfUndone, '-b');
        OrderRows::insert($givenUp);
        $repository->recordFailedAbandon($givenUp);
        $repository->recordFailedAbandon($givenUp);

        $stale = array_values(array_filter(
            $repository->getStaleQuoteIds(30, 2, 1000, false),
            static fn (int $quoteId): bool => $quoteId >= self::QUOTE_ID && $quoteId <= self::QUOTE_ID + 6
        ));

        self::assertSame([$halfUndone, $unlinked, $linked], $stale);
        self::assertCount(1, $repository->getStaleQuoteIds(30, 2, 1, false));
        self::assertContains($givenUp, $repository->getStaleQuoteIds(30, 3, 1000, false));
    }

    /**
     * While cancelled orders are still to be deleted, a cart stays named as long as a cancelled order of it exists,
     * although that order is undone: its delete may have failed, or someone else cancelled it. An undone order past
     * cancelling does not count: nothing more can be done with it, and it would hold a place in the batch for ever.
     * While nothing is deleted, an undone order keeps the cart unnamed whatever its state.
     *
     * @return void
     */
    public function testACancelledOrderKeepsItsCartNamedWhileCancelledOrdersAreDeleted(): void
    {
        $cancelled = self::QUOTE_ID;
        $pastCancelling = self::QUOTE_ID + 1;
        $this->repositoryAt('2026-10-02 09:00:00')->recognise($cancelled);
        $this->repositoryAt('2026-10-02 09:00:00')->recognise($pastCancelling);
        $this->undoClaims()->claim(OrderRows::insert($cancelled, '', 'canceled'));
        $this->undoClaims()->claim(OrderRows::insert($pastCancelling, '', 'processing'));

        $repository = $this->repositoryAt('2026-10-02 10:00:00');

        self::assertContains($cancelled, $repository->getStaleQuoteIds(30, 5, 1000, true));
        self::assertNotContains($pastCancelling, $repository->getStaleQuoteIds(30, 5, 1000, true));
        self::assertNotContains($cancelled, $repository->getStaleQuoteIds(30, 5, 1000, false));
        self::assertNotContains($pastCancelling, $repository->getStaleQuoteIds(30, 5, 1000, false));
    }

    /**
     * An undone closed order does not count, whether or not cancelled orders are still to be deleted: it is ended, and
     * nothing would ever take it out of the batch. Closed and never undone, it counts once, as any other order not
     * undone does: ending it claims the undo and takes it out.
     *
     * @return void
     */
    public function testAnUndoneClosedOrderDoesNotKeepItsCartNamed(): void
    {
        $undone = self::QUOTE_ID;
        $notUndone = self::QUOTE_ID + 1;
        $this->repositoryAt('2026-10-02 09:00:00')->recognise($undone);
        $this->repositoryAt('2026-10-02 09:00:00')->recognise($notUndone);
        $this->undoClaims()->claim(OrderRows::insert($undone, '', 'closed'));
        $closedByAPerson = OrderRows::insert($notUndone, '', 'closed');

        $repository = $this->repositoryAt('2026-10-02 10:00:00');

        self::assertNotContains($undone, $repository->getStaleQuoteIds(30, 5, 1000, true));
        self::assertNotContains($undone, $repository->getStaleQuoteIds(30, 5, 1000, false));
        self::assertContains($notUndone, $repository->getStaleQuoteIds(30, 5, 1000, false));

        $this->undoClaims()->claim($closedByAPerson);

        self::assertNotContains($notUndone, $repository->getStaleQuoteIds(30, 5, 1000, false));
    }

    /**
     * A cart given up after too many failed attempts is not named for a cancelled order either.
     *
     * @return void
     */
    public function testAGivenUpCartIsNotNamedForACancelledOrder(): void
    {
        $repository = $this->repositoryAt('2026-10-02 10:00:00');
        $this->repositoryAt('2026-10-02 09:00:00')->recognise(self::QUOTE_ID);
        $this->undoClaims()->claim(OrderRows::insert(self::QUOTE_ID, '', 'canceled'));
        $repository->recordFailedAbandon(self::QUOTE_ID);
        $repository->recordFailedAbandon(self::QUOTE_ID);

        self::assertNotContains(self::QUOTE_ID, $repository->getStaleQuoteIds(30, 2, 1000, true));
    }

    /**
     * Records of carts from which no order exists go once they are old enough; a record whose order exists — linked
     * or only naming the cart — stays, and so does a recent one.
     *
     * @return void
     */
    public function testOnlyOldRecordsWithoutAnOrderAreRemoved(): void
    {
        $orderless = self::QUOTE_ID;
        $unlinked = self::QUOTE_ID + 1;
        $linked = self::QUOTE_ID + 2;
        $recent = self::QUOTE_ID + 3;

        $this->repositoryAt('2026-10-01 08:00:00')->recognise($orderless);
        $this->repositoryAt('2026-10-01 08:00:00')->recognise($unlinked);
        $this->repositoryAt('2026-10-01 08:00:00')->recognise($linked);
        $this->repositoryAt('2026-10-02 09:00:00')->recognise($recent);
        OrderRows::insert($unlinked);
        $repository = $this->repositoryAt('2026-10-02 10:00:00');
        $repository->linkOrder($linked, OrderRows::insert($linked));

        self::assertGreaterThanOrEqual(1, $repository->removeOrderless(1440));

        self::assertSame(0, $this->recordCount($orderless));
        self::assertSame(1, $this->recordCount($unlinked));
        self::assertSame(1, $this->recordCount($linked));
        self::assertSame(1, $this->recordCount($recent));
    }

    /**
     * The container's undo claims.
     *
     * @return UndoClaimsInterface
     */
    private function undoClaims(): UndoClaimsInterface
    {
        $claims = Bootstrap::getObjectManager()->get(UndoClaimsInterface::class);
        self::assertInstanceOf(UndoClaimsInterface::class, $claims);

        return $claims;
    }

    /**
     * The repository from the container's own wiring, reading a fixed time.
     *
     * @param string $now UTC time the clock reads
     * @return TestOrderRepository
     */
    private function repositoryAt(string $now): TestOrderRepository
    {
        $timestamp = (int)strtotime($now . ' UTC');
        $clock = $this->createStub(DateTime::class);
        $clock->method('gmtTimestamp')->willReturn($timestamp);
        $clock->method('gmtDate')->willReturnCallback(
            static fn (?string $format = null, int|string|null $input = null): string => gmdate(
                $format ?? 'Y-m-d H:i:s',
                is_numeric($input) ? (int)$input : $timestamp
            )
        );

        $repository = Bootstrap::getObjectManager()->create(TestOrderRepository::class, ['clock' => $clock]);
        self::assertInstanceOf(TestOrderRepository::class, $repository);

        return $repository;
    }

    /**
     * How many records the table holds for a cart.
     *
     * @param int $quoteId Cart id
     * @return int
     */
    private function recordCount(int $quoteId): int
    {
        $resource = Bootstrap::getObjectManager()->get(ResourceConnection::class);
        self::assertInstanceOf(ResourceConnection::class, $resource);
        $connection = $resource->getConnection();
        $count = $connection->fetchOne(
            $connection->select()
                ->from($resource->getTableName(TestOrderRepository::TABLE_NAME), ['COUNT(*)'])
                ->where('quote_id = ?', $quoteId)
        );

        return is_numeric($count) ? (int)$count : -1;
    }
}
