<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\TestOrder\Test\Unit\Model;

use Hryvinskyi\TestOrder\Api\LivePaymentsDeclarationInterface;
use Hryvinskyi\TestOrder\Model\OfflinePaidTestOrderRefund;
use Magento\Framework\Api\SearchCriteria;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Sales\Api\CreditmemoManagementInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\Data\OrderSearchResultInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Creditmemo;
use Magento\Sales\Model\Order\Creditmemo\Item;
use Magento\Sales\Model\Order\CreditmemoFactory;
use Magento\Sales\Model\OrderMutexInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * A paid test order is refunded offline and whole, with every item back to stock, unless the shop declares live
 * payments or the order, as read under its lock, cannot take a credit memo.
 */
class OfflinePaidTestOrderRefundTest extends TestCase
{
    private const int ORDER_ID = 42;

    /**
     * @var CreditmemoFactory&MockObject
     */
    private CreditmemoFactory&MockObject $creditmemoFactory;

    /**
     * @var CreditmemoManagementInterface&MockObject
     */
    private CreditmemoManagementInterface&MockObject $creditmemoManagement;

    /**
     * @var bool Whether the fake lock is held right now
     */
    private bool $locked = false;

    /**
     * @var list<int> The orders the fake lock was taken on
     */
    private array $lockedOrders = [];

    /**
     * @inheritDoc
     */
    protected function setUp(): void
    {
        $this->creditmemoFactory = $this->createMock(CreditmemoFactory::class);
        $this->creditmemoManagement = $this->createMock(CreditmemoManagementInterface::class);
    }

    /**
     * The credit memo of the whole order is refunded offline, which is the second argument, and the answer is the order
     * the refund saved, not the copy handed in.
     *
     * @return void
     */
    public function testAPaidOrderIsRefundedOffline(): void
    {
        $order = $this->order(canCreditmemo: true);
        $creditmemo = $this->createMock(Creditmemo::class);
        $creditmemo->method('getItems')->willReturn([]);
        $this->creditmemoFactory->expects(self::once())->method('createByOrder')->with($order)
            ->willReturn($creditmemo);
        $this->creditmemoManagement->expects(self::once())->method('refund')->with($creditmemo, true);

        self::assertSame($order, $this->refund(livePayments: false, asItStands: $order)->refund($this->stale()));
    }

    /**
     * The copy handed in only names the order. A copy another refund went stale on says it can take a credit memo, and
     * the order as it stands, already closed, is what decides and what the answer is.
     *
     * @return void
     */
    public function testTheOrderAsItStandsDecidesNotTheCopyHandedIn(): void
    {
        $this->creditmemoFactory->expects(self::never())->method('createByOrder');
        $this->creditmemoManagement->expects(self::never())->method('refund');

        $closed = $this->order(canCreditmemo: false);
        $refund = $this->refund(livePayments: false, asItStands: $closed);

        self::assertSame($closed, $refund->refund($this->stale()));
    }

    /**
     * The read, the decision and the write all happen while the lock on this order is held, or two refunds could still
     * both decide before either wrote.
     *
     * @return void
     */
    public function testTheDecisionAndTheWriteHappenUnderTheOrdersLock(): void
    {
        $during = [];
        $order = $this->createMock(Order::class);
        $order->method('canCreditmemo')->willReturnCallback(function () use (&$during): bool {
            $during['decision'] = $this->locked;

            return true;
        });
        $creditmemo = $this->createMock(Creditmemo::class);
        $creditmemo->method('getItems')->willReturn([]);
        $this->creditmemoFactory->method('createByOrder')->willReturnCallback(
            function () use (&$during, $creditmemo): Creditmemo {
                $during['credit memo'] = $this->locked;

                return $creditmemo;
            }
        );
        $this->creditmemoManagement->method('refund')->willReturnCallback(
            function () use (&$during, $creditmemo): Creditmemo {
                $during['refund'] = $this->locked;

                return $creditmemo;
            }
        );

        $this->refund(livePayments: false, asItStands: $order, whenRead: function () use (&$during): void {
            $during['read'] = $this->locked;
        })->refund($this->stale());

        self::assertSame(['read' => true, 'decision' => true, 'credit memo' => true, 'refund' => true], $during);
        self::assertSame([self::ORDER_ID], $this->lockedOrders);
    }

    /**
     * Every item is marked for return to stock before the refund runs: the stock side reads the mark during the
     * refund, and the credit memo the factory builds carries none.
     *
     * @return void
     */
    public function testEveryItemGoesBackToStockBeforeTheRefund(): void
    {
        $marked = [];
        $mark = function (string $key, bool $value) use (&$marked): void {
            $marked[] = [$key, $value];
        };
        $items = [$this->item($mark), $this->item($mark)];
        $creditmemo = $this->createMock(Creditmemo::class);
        $creditmemo->method('getItems')->willReturn($items);
        $this->creditmemoFactory->method('createByOrder')->willReturn($creditmemo);
        $markedAtRefund = [];
        $this->creditmemoManagement->method('refund')->willReturnCallback(
            function () use (&$marked, &$markedAtRefund, $creditmemo): Creditmemo {
                $markedAtRefund = $marked;

                return $creditmemo;
            }
        );

        $this->refund(livePayments: false, asItStands: $this->order(canCreditmemo: true))->refund($this->stale());

        self::assertSame([['back_to_stock', true], ['back_to_stock', true]], $markedAtRefund);
    }

    /**
     * On a shop that declares live payments an offline credit memo would hide real money, so nothing is touched: the
     * order is not read, not asked whether it could take one, and not locked, and the answer is the order handed in.
     *
     * @return void
     */
    public function testDeclaredLivePaymentsAreRefusedAndNothingIsTouched(): void
    {
        $order = $this->createMock(Order::class);
        $order->expects(self::never())->method('canCreditmemo');
        $this->creditmemoFactory->expects(self::never())->method('createByOrder');
        $this->creditmemoManagement->expects(self::never())->method('refund');

        self::assertSame($order, $this->refund(livePayments: true, asItStands: $this->stale())->refund($order));
        self::assertSame([], $this->lockedOrders);
    }

    /**
     * An order that cannot take a credit memo is refused, and answers as it was read under the lock.
     *
     * @return void
     */
    public function testAnOrderThatCannotTakeACreditMemoIsRefused(): void
    {
        $this->creditmemoFactory->expects(self::never())->method('createByOrder');
        $this->creditmemoManagement->expects(self::never())->method('refund');
        $order = $this->order(canCreditmemo: false);

        self::assertSame($order, $this->refund(livePayments: false, asItStands: $order)->refund($this->stale()));
    }

    /**
     * An order that is not Magento's own model cannot be asked whether it takes a credit memo, so it is refused, and
     * answers as it was read.
     *
     * @return void
     */
    public function testAnOrderThatIsNotAMagentoOrderIsRefused(): void
    {
        $this->creditmemoFactory->expects(self::never())->method('createByOrder');
        $this->creditmemoManagement->expects(self::never())->method('refund');

        $order = $this->createStub(OrderInterface::class);

        self::assertSame($order, $this->refund(livePayments: false, asItStands: $order)->refund($this->stale()));
    }

    /**
     * An order deleted before the lock came free has nothing left to refund, and the answer is the copy handed in.
     *
     * @return void
     */
    public function testAnOrderThatNoLongerExistsIsRefused(): void
    {
        $this->creditmemoManagement->expects(self::never())->method('refund');

        $stale = $this->stale();

        self::assertSame($stale, $this->refund(livePayments: false, asItStands: null)->refund($stale));
    }

    /**
     * A failing refund comes out through the lock as it is: that is what makes the lock's transaction roll back, and
     * the caller reports it.
     *
     * @return void
     */
    public function testAFailingRefundIsPassedOn(): void
    {
        $creditmemo = $this->createMock(Creditmemo::class);
        $creditmemo->method('getItems')->willReturn([]);
        $this->creditmemoFactory->method('createByOrder')->willReturn($creditmemo);
        $failure = new \RuntimeException('The credit memo could not be saved.');
        $this->creditmemoManagement->method('refund')->willThrowException($failure);

        try {
            $this->refund(livePayments: false, asItStands: $this->order(canCreditmemo: true))->refund($this->stale());
            self::fail('A failed refund was reported as done.');
        } catch (\RuntimeException $passedOn) {
            self::assertSame($failure, $passedOn);
        }
    }

    /**
     * The refund over the shop's declaration, a lock that runs what it is given and an order store of one order.
     *
     * @param bool $livePayments Whether the shop declares live payments
     * @param OrderInterface|null $asItStands The order a read under the lock finds; null when it finds none
     * @param (callable(): void)|null $whenRead Told when the order is read
     * @return OfflinePaidTestOrderRefund
     */
    private function refund(
        bool $livePayments,
        ?OrderInterface $asItStands,
        ?callable $whenRead = null
    ): OfflinePaidTestOrderRefund {
        $declaration = $this->createMock(LivePaymentsDeclarationInterface::class);
        $declaration->method('allowsTestOrdersOnLivePayments')->willReturn($livePayments);

        $mutex = $this->createMock(OrderMutexInterface::class);
        $mutex->method('execute')->willReturnCallback(
            function (int $orderId, callable $callable, array $args = []): mixed {
                $this->lockedOrders[] = $orderId;
                $this->locked = true;
                try {
                    return $callable(...$args);
                } finally {
                    $this->locked = false;
                }
            }
        );

        $criteria = $this->createStub(SearchCriteria::class);
        $criteriaBuilder = $this->createMock(SearchCriteriaBuilder::class);
        $criteriaBuilder->method('addFilter')->with(OrderInterface::ENTITY_ID, self::ORDER_ID)->willReturnSelf();
        $criteriaBuilder->method('create')->willReturn($criteria);
        $found = $this->createMock(OrderSearchResultInterface::class);
        $found->method('getItems')->willReturn($asItStands === null ? [] : [self::ORDER_ID => $asItStands]);
        $orders = $this->createMock(OrderRepositoryInterface::class);
        $orders->expects(self::never())->method('get');
        $orders->method('getList')->with($criteria)->willReturnCallback(
            static function () use ($found, $whenRead): OrderSearchResultInterface {
                if ($whenRead !== null) {
                    $whenRead();
                }

                return $found;
            }
        );

        return new OfflinePaidTestOrderRefund(
            $declaration,
            $this->creditmemoFactory,
            $this->creditmemoManagement,
            $mutex,
            $orders,
            $criteriaBuilder
        );
    }

    /**
     * The copy a caller holds, read while the order was open: asking it anything fails the test.
     *
     * @return Order&MockObject
     */
    private function stale(): Order&MockObject
    {
        $order = $this->createMock(Order::class);
        $order->method('getEntityId')->willReturn(self::ORDER_ID);
        $order->expects(self::never())->method('canCreditmemo');

        return $order;
    }

    /**
     * An order that can or cannot take a credit memo.
     *
     * @param bool $canCreditmemo Whether it can
     * @return Order&MockObject
     */
    private function order(bool $canCreditmemo): Order&MockObject
    {
        $order = $this->createMock(Order::class);
        $order->method('canCreditmemo')->willReturn($canCreditmemo);

        return $order;
    }

    /**
     * A credit memo item that tells what is set on it.
     *
     * @param callable(string, bool): void $mark Told each key and value set
     * @return Item&MockObject
     */
    private function item(callable $mark): Item&MockObject
    {
        $item = $this->createMock(Item::class);
        $item->method('setData')->willReturnCallback(
            function (string $key, bool $value) use ($item, $mark): Item {
                $mark($key, $value);

                return $item;
            }
        );

        return $item;
    }
}
