<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\TestOrder\Test\Unit\Cron;

use Hryvinskyi\TestOrder\Api\AbandonTestOrderInterface;
use Hryvinskyi\TestOrder\Api\CartOrdersInterface;
use Hryvinskyi\TestOrder\Api\CleanupConfigInterface;
use Hryvinskyi\TestOrder\Api\StaleTestCartsInterface;
use Hryvinskyi\TestOrder\Cron\AbandonStaleTestOrders;
use Hryvinskyi\TestOrder\Model\Data\AbandonResult;
use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Api\Data\OrderInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The clean-up job abandons the test orders left behind longer than configured, one failure never stopping the run;
 * each failure is counted, and a cart that failed five times is reported as critical with its order numbers and no
 * longer tried; then the records of carts that never became an order are removed after a day.
 */
class AbandonStaleTestOrdersTest extends TestCase
{
    /**
     * @var StaleTestCartsInterface&MockObject
     */
    private StaleTestCartsInterface&MockObject $staleCarts;

    /**
     * @var AbandonTestOrderInterface&MockObject
     */
    private AbandonTestOrderInterface&MockObject $abandonTestOrder;

    /**
     * @var CartOrdersInterface&MockObject
     */
    private CartOrdersInterface&MockObject $cartOrders;

    /**
     * @var LoggerInterface&MockObject
     */
    private LoggerInterface&MockObject $logger;

    /**
     * @inheritDoc
     */
    protected function setUp(): void
    {
        $this->staleCarts = $this->createMock(StaleTestCartsInterface::class);
        $this->abandonTestOrder = $this->createMock(AbandonTestOrderInterface::class);
        $this->cartOrders = $this->createMock(CartOrdersInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
    }

    /**
     * Every cart left behind longer than configured, and not yet given up, is abandoned; then the orderless records
     * of a day ago go.
     *
     * @return void
     */
    public function testEveryTestOrderLeftBehindIsAbandoned(): void
    {
        $this->staleCarts->expects(self::once())->method('getStaleQuoteIds')
            ->with(45, AbandonStaleTestOrders::MAX_FAILED_ATTEMPTS, 50, false)
            ->willReturn([11, 12]);
        $abandoned = [];
        $this->abandonTestOrder->expects(self::exactly(2))->method('abandon')
            ->willReturnCallback(static function (int $quoteId) use (&$abandoned): AbandonResult {
                $abandoned[] = $quoteId;

                return new AbandonResult('canceled', false);
            });
        $this->staleCarts->expects(self::never())->method('recordFailedAbandon');
        $this->staleCarts->expects(self::once())->method('removeOrderless')->with(1440);

        $this->job(45)->execute();

        self::assertSame([11, 12], $abandoned);
    }

    /**
     * While cancelled test orders are deleted, the selection is told so, and names a cart whose delete failed.
     *
     * @return void
     */
    public function testTheSelectionIsToldWhetherCancelledOrdersAreDeleted(): void
    {
        $this->staleCarts->expects(self::once())->method('getStaleQuoteIds')
            ->with(30, AbandonStaleTestOrders::MAX_FAILED_ATTEMPTS, 50, true)
            ->willReturn([]);

        $this->job(30, deletes: true)->execute();
    }

    /**
     * A cart that cannot be abandoned has the failure counted and reported as an error with its cart and attempt, and
     * the rest of the run goes on.
     *
     * @return void
     */
    public function testAFailureIsCountedAndReportedAndTheRunGoesOn(): void
    {
        $this->staleCarts->method('getStaleQuoteIds')->willReturn([11, 12]);
        $abandoned = [];
        $this->abandonTestOrder->method('abandon')
            ->willReturnCallback(static function (int $quoteId) use (&$abandoned): AbandonResult {
                if ($quoteId === 11) {
                    throw new LocalizedException(__('The order could not be cancelled.'));
                }
                $abandoned[] = $quoteId;

                return new AbandonResult('canceled', false);
            });
        $this->staleCarts->expects(self::once())->method('recordFailedAbandon')->with(11)->willReturn(2);
        $this->logger->expects(self::once())->method('error')->with(
            self::logicalAnd(
                self::stringContains('cart 11'),
                self::stringContains('attempt 2 of 5'),
                self::stringContains('could not be cancelled')
            )
        );
        $this->logger->expects(self::never())->method('critical');
        $this->staleCarts->expects(self::once())->method('removeOrderless');

        $this->job(30)->execute();

        self::assertSame([12], $abandoned);
    }

    /**
     * An engine error, such as a type error in a third party's observer of the cancellation, is counted and reported
     * like any other failure: the later carts are tried and the orderless records still go.
     *
     * @return void
     */
    public function testAnEngineErrorIsCountedAndTheRunGoesOn(): void
    {
        $this->staleCarts->method('getStaleQuoteIds')->willReturn([11, 12]);
        $abandoned = [];
        $this->abandonTestOrder->method('abandon')
            ->willReturnCallback(static function (int $quoteId) use (&$abandoned): AbandonResult {
                if ($quoteId === 11) {
                    throw new \TypeError('An observer was handed the wrong type.');
                }
                $abandoned[] = $quoteId;

                return new AbandonResult('canceled', false);
            });
        $this->staleCarts->expects(self::once())->method('recordFailedAbandon')->with(11)->willReturn(1);
        $this->logger->expects(self::once())->method('error')->with(
            self::logicalAnd(self::stringContains('cart 11'), self::stringContains('the wrong type'))
        );
        $this->staleCarts->expects(self::once())->method('removeOrderless');

        $this->job(30)->execute();

        self::assertSame([12], $abandoned);
    }

    /**
     * The fifth failure gives the cart up: it is reported as critical with the cart and the numbers of its orders.
     *
     * @return void
     */
    public function testTheFifthFailureIsReportedAsCriticalWithTheOrderNumbers(): void
    {
        $this->staleCarts->method('getStaleQuoteIds')->willReturn([11]);
        $this->abandonTestOrder->method('abandon')
            ->willThrowException(new LocalizedException(__('The order could not be cancelled.')));
        $this->staleCarts->method('recordFailedAbandon')->with(11)
            ->willReturn(AbandonStaleTestOrders::MAX_FAILED_ATTEMPTS);
        $this->cartOrders->method('ofCart')->with(11)
            ->willReturn([$this->order('100000041'), $this->order('100000042')]);
        $this->logger->expects(self::never())->method('error');
        $this->logger->expects(self::once())->method('critical')->with(
            self::logicalAnd(
                self::stringContains('cart 11'),
                self::stringContains('100000041, 100000042'),
                self::stringContains('after 5 attempts'),
                self::stringContains('could not be cancelled')
            )
        );

        $this->job(30)->execute();
    }

    /**
     * The order numbers of a given-up cart that cannot be read do not keep the failure from being reported.
     *
     * @return void
     */
    public function testAGivenUpCartIsReportedEvenWhenItsOrdersCannotBeRead(): void
    {
        $this->staleCarts->method('getStaleQuoteIds')->willReturn([11]);
        $this->abandonTestOrder->method('abandon')
            ->willThrowException(new LocalizedException(__('The order could not be cancelled.')));
        $this->staleCarts->method('recordFailedAbandon')->willReturn(6);
        $this->cartOrders->method('ofCart')->willThrowException(new \RuntimeException('Database gone.'));
        $this->logger->expects(self::once())->method('critical')
            ->with(self::logicalAnd(self::stringContains('cart 11'), self::stringContains('unreadable')));

        $this->job(30)->execute();
    }

    /**
     * An engine error while reading the order numbers does not keep a given-up cart from being reported.
     *
     * @return void
     */
    public function testAnEngineErrorReadingTheOrderNumbersDoesNotHideTheReport(): void
    {
        $this->staleCarts->method('getStaleQuoteIds')->willReturn([11]);
        $this->abandonTestOrder->method('abandon')
            ->willThrowException(new LocalizedException(__('The order could not be cancelled.')));
        $this->staleCarts->method('recordFailedAbandon')->willReturn(5);
        $this->cartOrders->method('ofCart')->willThrowException(new \TypeError('The wrong type.'));
        $this->logger->expects(self::once())->method('critical')
            ->with(self::logicalAnd(self::stringContains('cart 11'), self::stringContains('unreadable')));

        $this->job(30)->execute();
    }

    /**
     * An order of the cart, by its number.
     *
     * @param string $incrementId Order number
     * @return OrderInterface
     */
    private function order(string $incrementId): OrderInterface
    {
        $order = $this->createMock(OrderInterface::class);
        $order->method('getIncrementId')->willReturn($incrementId);

        return $order;
    }

    /**
     * The job, with the configured minutes.
     *
     * @param int $minutes Minutes after which a test order is abandoned
     * @param bool $deletes Whether cancelled test orders are deleted
     * @return AbandonStaleTestOrders
     */
    private function job(int $minutes, bool $deletes = false): AbandonStaleTestOrders
    {
        $config = $this->createMock(CleanupConfigInterface::class);
        $config->method('abandonAfterMinutes')->willReturn($minutes);
        $config->method('deletesCancelledTestOrders')->willReturn($deletes);

        return new AbandonStaleTestOrders(
            $this->staleCarts,
            $this->abandonTestOrder,
            $this->cartOrders,
            $config,
            $this->logger
        );
    }
}
