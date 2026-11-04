<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\TestOrder\Test\Unit\Plugin;

use Hryvinskyi\TestOrder\Api\RequestProofInterface;
use Hryvinskyi\TestOrder\Api\TestCartRecognitionInterface;
use Hryvinskyi\TestOrder\Api\TestCartRecordsInterface;
use Hryvinskyi\TestOrder\Api\TestCartsInterface;
use Hryvinskyi\TestOrder\Plugin\RecogniseTestCart;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Quote\Api\CartManagementInterface;
use Magento\Quote\Model\CartLockedException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * A placement without a proof is left alone; with a proof, the cart is recognised before the order is placed, and a
 * refusal stops the placement; and a placement that fails forgets only a record it made itself, passing the failure on
 * unchanged. Which cart may be recognised is {@see \Hryvinskyi\TestOrder\Test\Unit\Model\TestCartRecognitionTest}'s.
 */
class RecogniseTestCartTest extends TestCase
{
    private const int QUOTE_ID = 4711;

    private const int PLACED_ORDER_ID = 812;

    /**
     * @var TestCartRecordsInterface&MockObject
     */
    private TestCartRecordsInterface&MockObject $records;

    /**
     * @var TestCartRecognitionInterface&MockObject
     */
    private TestCartRecognitionInterface&MockObject $recognition;

    /**
     * @var list<string> What happened, in order: the cart recognised or forgotten, and the placement
     */
    private array $journal = [];

    /**
     * @inheritDoc
     */
    protected function setUp(): void
    {
        $this->records = $this->createMock(TestCartRecordsInterface::class);
        $this->recognition = $this->createMock(TestCartRecognitionInterface::class);
    }

    /**
     * Without a proof the placement is an ordinary one: nothing is asked about the cart, and the placement's answer is
     * handed back as it is.
     *
     * @return void
     */
    public function testAPlacementWithoutAProofIsLeftAlone(): void
    {
        $this->recognition->expects(self::never())->method('recognise');
        $testCarts = $this->createMock(TestCartsInterface::class);
        $testCarts->expects(self::never())->method('isTestCart');

        $orderId = $this->plugin(proofSent: false, testCarts: $testCarts)
            ->aroundPlaceOrder($this->createMock(CartManagementInterface::class), $this->placement(), self::QUOTE_ID);

        self::assertSame((string)self::PLACED_ORDER_ID, $orderId);
        self::assertSame(['place ' . self::QUOTE_ID], $this->journal);
    }

    /**
     * With a proof the cart is recognised before the order is placed, whether the cart id arrives as an integer or as
     * the string a masked-id lookup returns.
     *
     * @dataProvider cartIds
     * @param int|string $cartId Cart id as the placement passes it
     * @return void
     */
    public function testAProofRecognisesTheCartBeforePlacing(int|string $cartId): void
    {
        $this->recordingRecognition();

        $orderId = $this->plugin()
            ->aroundPlaceOrder($this->createMock(CartManagementInterface::class), $this->placement(), $cartId);

        self::assertSame((string)self::PLACED_ORDER_ID, $orderId);
        self::assertSame(['recognise ' . self::QUOTE_ID, 'place ' . $cartId], $this->journal);
    }

    /**
     * @return array<string, array{int|string}>
     */
    public static function cartIds(): array
    {
        return [
            'integer' => [self::QUOTE_ID],
            'string' => [(string)self::QUOTE_ID],
        ];
    }

    /**
     * A refusal reaches the caller unchanged: nothing is placed and nothing is forgotten.
     *
     * @dataProvider refusals
     * @param \Throwable $refusal What recognition throws
     * @return void
     */
    public function testARefusalStopsThePlacement(\Throwable $refusal): void
    {
        $this->recognition->method('recognise')->willThrowException($refusal);
        $this->records->expects(self::never())->method('forgetOrderless');

        try {
            $this->plugin()->aroundPlaceOrder(
                $this->createMock(CartManagementInterface::class),
                $this->placement(),
                self::QUOTE_ID
            );
            self::fail('The placement was not refused.');
        } catch (LocalizedException $passedOn) {
            self::assertSame($refusal, $passedOn);
        }

        self::assertSame([], $this->journal);
    }

    /**
     * @return array<string, array{LocalizedException}>
     */
    public static function refusals(): array
    {
        return [
            'a proof that cannot be honoured' => [new LocalizedException(__('The order was not placed.'))],
            'a cart that does not exist' => [new NoSuchEntityException(__('No such cart.'))],
        ];
    }

    /**
     * A placement that fails after making the record forgets the cart — the record stays only when an order of the
     * cart exists — and the failure reaches the caller unchanged.
     *
     * @return void
     */
    public function testAFailedPlacementForgetsTheRecordItMadeAndPassesTheFailureOn(): void
    {
        $this->recordingRecognition();
        $this->recordingForgetting();
        $failure = new LocalizedException(__('The payment could not be started.'));

        self::assertSame($failure, $this->failureOfPlacing($failure));
        self::assertSame(
            ['recognise ' . self::QUOTE_ID, 'place ' . self::QUOTE_ID, 'forget ' . self::QUOTE_ID],
            $this->journal
        );
    }

    /**
     * A placement that loses the lock on a cart with no record forgets the record it made: the lock's holder may be a
     * customer's ordinary placement, whose order must not count as a test order. The failure reaches the caller
     * unchanged.
     *
     * @return void
     */
    public function testAPlacementThatLosesTheLockOnACartWithNoRecordLeavesNoRecord(): void
    {
        $this->recordingRecognition();
        $this->recordingForgetting();
        $failure = new CartLockedException(__('The cart is locked for processing. Please try again later.'));

        self::assertSame($failure, $this->failureOfPlacing($failure));
        self::assertSame(
            ['recognise ' . self::QUOTE_ID, 'place ' . self::QUOTE_ID, 'forget ' . self::QUOTE_ID],
            $this->journal
        );
    }

    /**
     * A placement that fails on a cart already recorded keeps the record, whatever stopped it: the lock held by the
     * placement the record belongs to, or anything further in, which says nothing about that placement. The failure
     * reaches the caller unchanged.
     *
     * @dataProvider failures
     * @param \Throwable $failure What the placement throws
     * @return void
     */
    public function testAPlacementThatFailsOnARecordedCartKeepsTheRecord(\Throwable $failure): void
    {
        $this->recordingRecognition();
        $this->records->expects(self::never())->method('forgetOrderless');

        self::assertSame($failure, $this->failureOfPlacing($failure, alreadyRecorded: true));
    }

    /**
     * @return array<string, array{\Throwable}>
     */
    public static function failures(): array
    {
        return [
            'lost the lock' => [new CartLockedException(__('The cart is locked for processing.'))],
            'failed further in' => [new LocalizedException(__('The payment could not be started.'))],
            'an error' => [new \TypeError('Argument #1 must be of type string.')],
        ];
    }

    /**
     * When the record cannot be forgotten, the placement's own failure still reaches the caller unchanged, and the
     * record left behind is reported.
     *
     * @return void
     */
    public function testAFailureToForgetDoesNotHideThePlacementsFailure(): void
    {
        $this->recordingRecognition();
        $this->records->method('forgetOrderless')
            ->willThrowException(new \RuntimeException('Lock wait timeout exceeded.'));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning')->with(
            self::logicalAnd(self::stringContains('cart ' . self::QUOTE_ID), self::stringContains('Lock wait'))
        );
        $failure = new \RuntimeException('The payment provider did not answer.');

        self::assertSame($failure, $this->failureOfPlacing($failure, logger: $logger));
    }

    /**
     * A placement without a proof that fails touches no record.
     *
     * @return void
     */
    public function testAFailedOrdinaryPlacementTouchesNoRecord(): void
    {
        $this->records->expects(self::never())->method('forgetOrderless');
        $failure = new LocalizedException(__('The payment could not be started.'));

        $this->expectExceptionObject($failure);

        $this->plugin(proofSent: false)->aroundPlaceOrder(
            $this->createMock(CartManagementInterface::class),
            $this->placement($failure),
            self::QUOTE_ID
        );
    }

    /**
     * Place with a proof, and hand back what stopped the placement.
     *
     * @param \Throwable $failure What the placement throws
     * @param bool $alreadyRecorded Whether the cart was recorded before the placement started
     * @param LoggerInterface|null $logger Where a record that could not be forgotten is reported
     * @return \Throwable
     */
    private function failureOfPlacing(
        \Throwable $failure,
        bool $alreadyRecorded = false,
        ?LoggerInterface $logger = null
    ): \Throwable {
        try {
            $this->plugin(alreadyRecorded: $alreadyRecorded, logger: $logger)->aroundPlaceOrder(
                $this->createMock(CartManagementInterface::class),
                $this->placement($failure),
                self::QUOTE_ID
            );
        } catch (\Throwable $passedOn) {
            return $passedOn;
        }

        self::fail('A failed placement was reported as placed.');
    }

    /**
     * Let recognition note each recognised cart in the journal.
     *
     * @return void
     */
    private function recordingRecognition(): void
    {
        $this->recognition->expects(self::once())->method('recognise')->with(self::QUOTE_ID)
            ->willReturnCallback(function (int $quoteId): void {
                $this->journal[] = 'recognise ' . $quoteId;
            });
    }

    /**
     * Let the records note each forgotten cart in the journal.
     *
     * @return void
     */
    private function recordingForgetting(): void
    {
        $this->records->expects(self::once())->method('forgetOrderless')->with(self::QUOTE_ID)
            ->willReturnCallback(function (int $quoteId): bool {
                $this->journal[] = 'forget ' . $quoteId;

                return true;
            });
    }

    /**
     * The placement the plugin wraps: notes itself in the journal, then answers the order id as a string (as the
     * framework does) or throws.
     *
     * @param \Throwable|null $failure What the placement throws; null when it succeeds
     * @return callable
     */
    private function placement(?\Throwable $failure = null): callable
    {
        return function (int|string $cartId) use ($failure): string {
            $this->journal[] = 'place ' . $cartId;
            if ($failure !== null) {
                throw $failure;
            }

            return (string)self::PLACED_ORDER_ID;
        };
    }

    /**
     * The plugin. By default a cart counts as a test cart once recognition has noted it, as the real question does, so
     * a plugin that asked after recognising would take its own record for another placement's.
     *
     * @param bool $proofSent Whether a proof was sent with the request
     * @param bool $alreadyRecorded Whether the cart was recorded before the placement started
     * @param TestCartsInterface|null $testCarts The question about the cart; answering as above when null
     * @param LoggerInterface|null $logger Where a record that could not be forgotten is reported
     * @return RecogniseTestCart
     */
    private function plugin(
        bool $proofSent = true,
        bool $alreadyRecorded = false,
        ?TestCartsInterface $testCarts = null,
        ?LoggerInterface $logger = null
    ): RecogniseTestCart {
        $requestProof = $this->createStub(RequestProofInterface::class);
        $requestProof->method('isSent')->willReturn($proofSent);

        if ($testCarts === null) {
            $testCarts = $this->createStub(TestCartsInterface::class);
            $testCarts->method('isTestCart')->willReturnCallback(
                fn (int $quoteId): bool => $alreadyRecorded || in_array('recognise ' . $quoteId, $this->journal, true)
            );
        }

        return new RecogniseTestCart(
            $requestProof,
            $this->recognition,
            $testCarts,
            $this->records,
            $logger ?? $this->createMock(LoggerInterface::class)
        );
    }
}
