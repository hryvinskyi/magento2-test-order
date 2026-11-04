<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\TestOrder\Test\Unit\Model;

use Hryvinskyi\TestOrder\Api\CartOrdersInterface;
use Hryvinskyi\TestOrder\Api\CleanupConfigInterface;
use Hryvinskyi\TestOrder\Api\Data\AbandonResultInterface;
use Hryvinskyi\TestOrder\Api\Data\AbandonResultInterfaceFactory;
use Hryvinskyi\TestOrder\Api\OrderDeletionInterface;
use Hryvinskyi\TestOrder\Api\TestOrderRepositoryInterface;
use Hryvinskyi\TestOrder\Api\UndoRunnerInterface;
use Hryvinskyi\TestOrder\Model\AbandonTestOrder;
use Hryvinskyi\TestOrder\Model\Data\AbandonResult;
use Hryvinskyi\TestOrder\Model\Data\TestOrder;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\OrderManagementInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Abandoning a recognised cart ends every order placed from it: each that can be cancelled is cancelled (which undoes
 * it), each that cannot is undone directly, kept and reported for a person, each cancelled one is deleted when deletion
 * is on, and the answer reports an order left open or else the newest — deleted only when all were.
 */
class AbandonTestOrderTest extends TestCase
{
    private const int QUOTE_ID = 4711;

    private const int ORDER_ID = 812;

    private const int OLDER_ORDER_ID = 807;

    /**
     * @var TestOrderRepositoryInterface&MockObject
     */
    private TestOrderRepositoryInterface&MockObject $testOrderRepository;

    /**
     * @var array<int, string> State of each order of the cart, by order id
     */
    private array $states = [];

    /**
     * @var list<int> Orders that can still be cancelled
     */
    private array $cancellable = [];

    /**
     * @var list<int> Orders whose cancellation throws
     */
    private array $failingCancellations = [];

    /**
     * @var \Throwable|null What a failing cancellation throws; a refusal naming the order when null
     */
    private ?\Throwable $cancellationFailure = null;

    /**
     * @var list<int> Orders cancelled, in order
     */
    private array $cancelled = [];

    /**
     * @var list<int> Orders undone directly, in order
     */
    private array $undone = [];

    /**
     * @var list<int> Orders deleted, in order
     */
    private array $deleted = [];

    /**
     * @inheritDoc
     */
    protected function setUp(): void
    {
        $this->testOrderRepository = $this->createMock(TestOrderRepositoryInterface::class);
        $this->testOrderRepository->method('getByQuoteId')->with(self::QUOTE_ID)
            ->willReturn(new TestOrder(31, self::QUOTE_ID, '2026-10-02 10:00:00', self::ORDER_ID));
    }

    /**
     * A pending test order is cancelled, undone, and, with deletion on, deleted.
     *
     * @return void
     */
    public function testAPendingTestOrderIsCancelledAndDeletedWhenDeletionIsOn(): void
    {
        $this->cart([self::ORDER_ID => 'new'], cancellable: [self::ORDER_ID]);

        $result = $this->abandonTestOrder(deletes: true)->abandon(self::QUOTE_ID);

        self::assertSame([self::ORDER_ID], $this->cancelled);
        self::assertSame([self::ORDER_ID], $this->undone);
        self::assertSame([self::ORDER_ID], $this->deleted);
        self::assertSame('canceled', $result->getState());
        self::assertTrue($result->isDeleted());
        self::assertSame('1000' . self::ORDER_ID, $result->getIncrementId());
    }

    /**
     * With deletion off, the cancelled test order is kept and reported as cancelled.
     *
     * @return void
     */
    public function testACancelledTestOrderIsKeptWhenDeletionIsOff(): void
    {
        $this->cart([self::ORDER_ID => 'new'], cancellable: [self::ORDER_ID]);

        $result = $this->abandonTestOrder(deletes: false)->abandon(self::QUOTE_ID);

        self::assertSame([], $this->deleted);
        self::assertSame('canceled', $result->getState());
        self::assertFalse($result->isDeleted());
    }

    /**
     * An order that can no longer be cancelled is undone directly, keeps its state and is never deleted.
     *
     * @return void
     */
    public function testAnOrderThatCannotBeCancelledIsUndoneDirectlyAndKept(): void
    {
        $this->cart([self::ORDER_ID => 'processing']);

        $result = $this->abandonTestOrder(deletes: true)->abandon(self::QUOTE_ID);

        self::assertSame([self::ORDER_ID], $this->undone);
        self::assertSame([], $this->deleted);
        self::assertSame('processing', $result->getState());
        self::assertFalse($result->isDeleted());
    }

    /**
     * An order that can no longer be cancelled stays live and hidden from integrations, so a warning names it, its
     * state, and that a person must end it.
     *
     * @return void
     */
    public function testAnOrderThatCannotBeCancelledIsReportedForAPerson(): void
    {
        $this->cart([self::ORDER_ID => 'processing']);
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning')->with(
            self::logicalAnd(
                self::stringContains('1000' . self::ORDER_ID),
                self::stringContains('processing'),
                self::stringContains('person')
            )
        );

        $this->abandonTestOrder(deletes: false, logger: $logger)->abandon(self::QUOTE_ID);
    }

    /**
     * An order that ends cancelled reports nothing, whether this call cancelled it or it was cancelled already.
     *
     * @dataProvider cancelledOrders
     * @param string $state State of the order before
     * @param list<int> $cancellable Orders that can still be cancelled
     * @return void
     */
    public function testACancelledOrderIsNotReported(string $state, array $cancellable): void
    {
        $this->cart([self::ORDER_ID => $state], cancellable: $cancellable);
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('warning');

        $this->abandonTestOrder(deletes: false, logger: $logger)->abandon(self::QUOTE_ID);
    }

    /**
     * @return array<string, array{string, list<int>}>
     */
    public static function cancelledOrders(): array
    {
        return [
            'cancelled here' => ['new', [self::ORDER_ID]],
            'cancelled before' => ['canceled', []],
        ];
    }

    /**
     * Two orders placed from one cart — one saved by a placement that then failed, one by the retry — are both
     * cancelled, undone and deleted; the answer reports the newest.
     *
     * @return void
     */
    public function testEveryOrderOfTheCartIsEnded(): void
    {
        $this->cart(
            [self::OLDER_ORDER_ID => 'new', self::ORDER_ID => 'new'],
            cancellable: [self::OLDER_ORDER_ID, self::ORDER_ID]
        );

        $result = $this->abandonTestOrder(deletes: true)->abandon(self::QUOTE_ID);

        self::assertSame([self::OLDER_ORDER_ID, self::ORDER_ID], $this->cancelled);
        self::assertSame([self::OLDER_ORDER_ID, self::ORDER_ID], $this->undone);
        self::assertSame([self::OLDER_ORDER_ID, self::ORDER_ID], $this->deleted);
        self::assertSame('1000' . self::ORDER_ID, $result->getIncrementId());
        self::assertTrue($result->isDeleted());
    }

    /**
     * When one order of the cart cannot be deleted, the answer does not claim the cart's orders are gone.
     *
     * @return void
     */
    public function testTheAnswerIsDeletedOnlyWhenEveryOrderWasDeleted(): void
    {
        $this->cart([self::OLDER_ORDER_ID => 'processing', self::ORDER_ID => 'new'], cancellable: [self::ORDER_ID]);

        $result = $this->abandonTestOrder(deletes: true)->abandon(self::QUOTE_ID);

        self::assertSame([self::ORDER_ID], $this->deleted);
        self::assertSame([self::OLDER_ORDER_ID, self::ORDER_ID], $this->undone);
        self::assertFalse($result->isDeleted());
    }

    /**
     * An order left open answers for the cart even when a newer one was cancelled: the caller must learn that an order
     * of the cart is still live, and the newest order's state alone would hide it.
     *
     * @return void
     */
    public function testAnOrderLeftOpenAnswersForTheCartOverANewerCancelledOne(): void
    {
        $this->cart([self::OLDER_ORDER_ID => 'processing', self::ORDER_ID => 'new'], cancellable: [self::ORDER_ID]);

        $result = $this->abandonTestOrder(deletes: false)->abandon(self::QUOTE_ID);

        self::assertSame('processing', $result->getState());
        self::assertSame('1000' . self::OLDER_ORDER_ID, $result->getIncrementId());
    }

    /**
     * An order that fails to end does not keep the others from being ended; the failure is passed on afterwards.
     *
     * @return void
     */
    public function testAFailingOrderDoesNotStopTheOthersAndIsPassedOn(): void
    {
        $this->cart(
            [self::OLDER_ORDER_ID => 'new', self::ORDER_ID => 'new'],
            cancellable: [self::OLDER_ORDER_ID, self::ORDER_ID]
        );
        $this->failingCancellations = [self::OLDER_ORDER_ID];

        try {
            $this->abandonTestOrder(deletes: true)->abandon(self::QUOTE_ID);
            self::fail('A cart whose order could not be cancelled was reported as ended.');
        } catch (LocalizedException $failure) {
            self::assertSame('Order ' . self::OLDER_ORDER_ID . ' is locked.', $failure->getMessage());
        }

        self::assertSame([self::ORDER_ID], $this->cancelled);
        self::assertSame([self::ORDER_ID], $this->deleted);
    }

    /**
     * An engine error, such as a type error in a third party's observer of the cancellation, is treated like any other
     * failure: the other orders are still ended, and the error is passed on afterwards.
     *
     * @return void
     */
    public function testAnEngineErrorDoesNotStopTheOthersAndIsPassedOn(): void
    {
        $this->cart(
            [self::OLDER_ORDER_ID => 'new', self::ORDER_ID => 'new'],
            cancellable: [self::OLDER_ORDER_ID, self::ORDER_ID]
        );
        $this->failingCancellations = [self::OLDER_ORDER_ID];
        $this->cancellationFailure = new \TypeError('An observer was handed the wrong type.');

        try {
            $this->abandonTestOrder(deletes: true)->abandon(self::QUOTE_ID);
            self::fail('A cart whose order could not be cancelled was reported as ended.');
        } catch (\TypeError $failure) {
            self::assertSame($this->cancellationFailure, $failure);
        }

        self::assertSame([self::ORDER_ID], $this->cancelled);
    }

    /**
     * A cart that was recognised but from which no order exists cannot be abandoned.
     *
     * @return void
     */
    public function testACartWithoutAnOrderCannotBeAbandoned(): void
    {
        $this->cart([]);

        $this->expectException(NoSuchEntityException::class);
        $this->expectExceptionMessage('No order was placed from test cart ' . self::QUOTE_ID . '.');

        try {
            $this->abandonTestOrder(deletes: true)->abandon(self::QUOTE_ID);
        } finally {
            self::assertSame([], $this->cancelled);
            self::assertSame([], $this->undone);
        }
    }

    /**
     * A cart that was never recognised is refused, and no order is looked for.
     *
     * @return void
     */
    public function testACartNeverRecognisedIsRefused(): void
    {
        $testOrderRepository = $this->createMock(TestOrderRepositoryInterface::class);
        $testOrderRepository->method('getByQuoteId')
            ->willThrowException(new NoSuchEntityException(__('No test order was recognised for cart %1.', 9)));
        $this->testOrderRepository = $testOrderRepository;
        $cartOrders = $this->createMock(CartOrdersInterface::class);
        $cartOrders->expects(self::never())->method('ofCart');

        $this->expectException(NoSuchEntityException::class);

        $this->abandonTestOrder(deletes: true, cartOrders: $cartOrders)->abandon(self::QUOTE_ID);
    }

    /**
     * The orders placed from the cart, oldest first, with their states.
     *
     * @param array<int, string> $states State by order id, oldest first
     * @param list<int> $cancellable Orders that can still be cancelled
     * @return void
     */
    private function cart(array $states, array $cancellable = []): void
    {
        $this->states = $states;
        $this->cancellable = $cancellable;
    }

    /**
     * An order of the cart, whose state follows {@see self::$states}.
     *
     * @param int $orderId Order entity id
     * @return OrderInterface
     */
    private function order(int $orderId): OrderInterface
    {
        $order = $this->createMock(OrderInterface::class);
        $order->method('getEntityId')->willReturn($orderId);
        $order->method('getQuoteId')->willReturn(self::QUOTE_ID);
        $order->method('getIncrementId')->willReturn('1000' . $orderId);
        $order->method('getState')->willReturnCallback(fn (): string => $this->states[$orderId] ?? '');

        return $order;
    }

    /**
     * The use case over the cart, with the real answer.
     *
     * @param bool $deletes Whether cancelled test orders are deleted
     * @param CartOrdersInterface|null $cartOrders The cart's orders; those of {@see self::cart()} when null
     * @param LoggerInterface|null $logger Where an order past cancelling is reported
     * @return AbandonTestOrder
     */
    private function abandonTestOrder(
        bool $deletes,
        ?CartOrdersInterface $cartOrders = null,
        ?LoggerInterface $logger = null
    ): AbandonTestOrder {
        if ($cartOrders === null) {
            $cartOrders = $this->createMock(CartOrdersInterface::class);
            $cartOrders->method('ofCart')->with(self::QUOTE_ID)->willReturnCallback(
                fn (): array => array_map($this->order(...), array_keys($this->states))
            );
        }

        $orderRepository = $this->createMock(OrderRepositoryInterface::class);
        $orderRepository->method('get')->willReturnCallback(
            fn (int $orderId): OrderInterface => $this->order($orderId)
        );

        $orderDeletion = $this->createMock(OrderDeletionInterface::class);
        $orderDeletion->method('delete')->willReturnCallback(function (OrderInterface $order): void {
            $this->deleted[] = (int)$order->getEntityId();
        });

        $orderManagement = $this->createMock(OrderManagementInterface::class);
        $orderManagement->method('cancel')->willReturnCallback(function (int $orderId): bool {
            if (in_array($orderId, $this->failingCancellations, true)) {
                throw $this->cancellationFailure ?? new LocalizedException(__('Order %1 is locked.', $orderId));
            }
            if (!in_array($orderId, $this->cancellable, true)) {
                return false;
            }
            $this->states[$orderId] = 'canceled';
            $this->cancelled[] = $orderId;

            return true;
        });

        $undoRunner = $this->createMock(UndoRunnerInterface::class);
        $undoRunner->method('undo')->willReturnCallback(function (OrderInterface $order): bool {
            $this->undone[] = (int)$order->getEntityId();

            return true;
        });

        $config = $this->createMock(CleanupConfigInterface::class);
        $config->method('deletesCancelledTestOrders')->willReturn($deletes);

        $resultFactory = $this->getMockBuilder(AbandonResultInterfaceFactory::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['create'])
            ->getMock();
        $resultFactory->method('create')->willReturnCallback(
            static fn (array $data): AbandonResultInterface => new AbandonResult(
                is_string($data['state'] ?? null) ? $data['state'] : '',
                ($data['deleted'] ?? null) === true,
                is_string($data['incrementId'] ?? null) ? $data['incrementId'] : null
            )
        );

        return new AbandonTestOrder(
            $this->testOrderRepository,
            $cartOrders,
            $orderRepository,
            $orderDeletion,
            $orderManagement,
            $undoRunner,
            $config,
            $resultFactory,
            $logger ?? $this->createStub(LoggerInterface::class)
        );
    }
}
