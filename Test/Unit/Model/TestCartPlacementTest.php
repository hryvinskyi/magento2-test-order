<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\TestOrder\Test\Unit\Model;

use Hryvinskyi\TestOrder\Api\RequestProofInterface;
use Hryvinskyi\TestOrder\Api\TestCartRecognitionInterface;
use Hryvinskyi\TestOrder\Api\TestCartRecordsInterface;
use Hryvinskyi\TestOrder\Api\TestCartsInterface;
use Hryvinskyi\TestOrder\Model\TestCartPlacement;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Quote\Model\CartLockedException;
use Magento\Quote\Model\MaskedQuoteIdToQuoteIdInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * A placement without a proof is left alone; with a proof, the cart is recognised before the placement runs, and a
 * refusal stops it; and a placement that fails forgets only a record its own call made, passing the failure on
 * unchanged. Which cart may be recognised is {@see TestCartRecognitionTest}'s.
 */
class TestCartPlacementTest extends TestCase
{
    private const int QUOTE_ID = 4711;

    private const string MASKED_ID = 'aB3dE6gH9jK2mN5pQ8sT1vW4yZ7cF0hL';

    private const string PLACEMENT_ANSWER = '812';

    /**
     * @var TestCartRecordsInterface&MockObject
     */
    private TestCartRecordsInterface&MockObject $records;

    /**
     * @var TestCartRecognitionInterface&MockObject
     */
    private TestCartRecognitionInterface&MockObject $recognition;

    /**
     * @var MaskedQuoteIdToQuoteIdInterface&MockObject
     */
    private MaskedQuoteIdToQuoteIdInterface&MockObject $quoteIds;

    /**
     * @var list<string> What happened, in order: the cart recognised or forgotten, and the placement
     */
    private array $journal = [];

    /**
     * @var bool Whether the cart has a record, as the real records would tell
     */
    private bool $recorded = false;

    /**
     * @var int How many times a record came into being where there was none
     */
    private int $recordsMade = 0;

    /**
     * @inheritDoc
     */
    protected function setUp(): void
    {
        $this->records = $this->createMock(TestCartRecordsInterface::class);
        $this->recognition = $this->createMock(TestCartRecognitionInterface::class);
        $this->quoteIds = $this->createMock(MaskedQuoteIdToQuoteIdInterface::class);
        $this->quoteIds->method('execute')->with(self::MASKED_ID)->willReturn(self::QUOTE_ID);
    }

    /**
     * Without a proof nothing is looked up and no collaborator is touched, so an ordinary placement costs nothing and
     * an id that names no cart is left for the placement's own error.
     *
     * @dataProvider cartIdsOfOrdinaryPlacements
     * @param int|string $cartId Cart id as the caller passes it
     * @return void
     */
    public function testAPlacementWithoutAProofTouchesNoCollaborator(int|string $cartId): void
    {
        $this->recognition->expects(self::never())->method('recognise');
        $this->records->expects(self::never())->method('forgetOrderless');
        $quoteIds = $this->createMock(MaskedQuoteIdToQuoteIdInterface::class);
        $quoteIds->expects(self::never())->method('execute');
        $testCarts = $this->createMock(TestCartsInterface::class);
        $testCarts->expects(self::never())->method('isTestCart');

        $answer = $this->model(proofSent: false, testCarts: $testCarts, quoteIds: $quoteIds)
            ->place($cartId, $this->placement());

        self::assertSame(self::PLACEMENT_ANSWER, $answer);
        self::assertSame(['place'], $this->journal);
    }

    /**
     * @return array<string, array{int|string}>
     */
    public static function cartIdsOfOrdinaryPlacements(): array
    {
        return [
            'an entity id' => [self::QUOTE_ID],
            'a masked id' => [self::MASKED_ID],
            'an id that names no cart' => ['no such cart'],
        ];
    }

    /**
     * A placement without a proof that fails touches no record, and its failure reaches the caller unchanged.
     *
     * @return void
     */
    public function testAFailedOrdinaryPlacementTouchesNoRecord(): void
    {
        $this->records->expects(self::never())->method('forgetOrderless');
        $failure = new LocalizedException(__('The payment could not be started.'));

        $this->expectExceptionObject($failure);

        $this->model(proofSent: false)->place(self::QUOTE_ID, $this->placement($failure));
    }

    /**
     * With a proof the cart is recognised before the placement runs, by its entity id however it was addressed, and the
     * placement's answer is handed back as it is.
     *
     * @dataProvider cartIds
     * @param int|string $cartId Cart id as the caller passes it
     * @return void
     */
    public function testAProofRecognisesTheCartByItsEntityIdBeforePlacing(int|string $cartId): void
    {
        $this->recordingRecognition();

        $answer = $this->model()->place($cartId, $this->placement());

        self::assertSame(self::PLACEMENT_ANSWER, $answer);
        self::assertSame(['recognise ' . self::QUOTE_ID, 'place'], $this->journal);
    }

    /**
     * @return array<string, array{int|string}>
     */
    public static function cartIds(): array
    {
        return [
            'an integer entity id' => [self::QUOTE_ID],
            'a string entity id' => [(string)self::QUOTE_ID],
            'a masked id' => [self::MASKED_ID],
        ];
    }

    /**
     * The method the caller names reaches recognition as it is, and none stays none: the cart's own is recognition's to
     * read.
     *
     * @dataProvider namedMethods
     * @param string|null $paymentMethod Method the caller names
     * @return void
     */
    public function testTheMethodACallerNamesIsPassedOn(?string $paymentMethod): void
    {
        $this->recognition->expects(self::once())->method('recognise')->with(self::QUOTE_ID, $paymentMethod);

        $this->model()->place(self::QUOTE_ID, $this->placement(), $paymentMethod);
    }

    /**
     * What a caller can name.
     *
     * @return array<string, array{string|null}>
     */
    public static function namedMethods(): array
    {
        return [
            'a method' => ['a_payment_method'],
            'none yet' => [null],
        ];
    }

    /**
     * An entity id is not looked up as a masked id.
     *
     * @return void
     */
    public function testAnEntityIdIsNotResolved(): void
    {
        $this->recordingRecognition();
        $quoteIds = $this->createMock(MaskedQuoteIdToQuoteIdInterface::class);
        $quoteIds->expects(self::never())->method('execute');

        $this->model(quoteIds: $quoteIds)->place((string)self::QUOTE_ID, $this->placement());
    }

    /**
     * A masked id that names no cart stops everything, with the lookup's own exception: nothing is asked about a cart
     * that does not exist, and the placement never runs.
     *
     * @return void
     */
    public function testAMaskedIdOfNoCartIsNeitherRecognisedNorPlaced(): void
    {
        $noCart = new NoSuchEntityException(__('No such cart.'));
        $quoteIds = $this->createStub(MaskedQuoteIdToQuoteIdInterface::class);
        $quoteIds->method('execute')->willThrowException($noCart);
        $this->recognition->expects(self::never())->method('recognise');
        $testCarts = $this->createMock(TestCartsInterface::class);
        $testCarts->expects(self::never())->method('isTestCart');

        try {
            $this->model(testCarts: $testCarts, quoteIds: $quoteIds)->place(self::MASKED_ID, $this->placement());
            self::fail('The placement was not refused.');
        } catch (NoSuchEntityException $passedOn) {
            self::assertSame($noCart, $passedOn);
        }

        self::assertSame([], $this->journal);
    }

    /**
     * A refusal reaches the caller unchanged: the placement never runs and nothing is forgotten.
     *
     * @dataProvider refusals
     * @param LocalizedException $refusal What recognition throws
     * @return void
     */
    public function testARefusalStopsThePlacement(LocalizedException $refusal): void
    {
        $this->recognition->method('recognise')->willThrowException($refusal);
        $this->records->expects(self::never())->method('forgetOrderless');

        try {
            $this->model()->place(self::QUOTE_ID, $this->placement());
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
        self::assertSame(['recognise ' . self::QUOTE_ID, 'place', 'forget ' . self::QUOTE_ID], $this->journal);
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
        self::assertSame(['recognise ' . self::QUOTE_ID, 'place', 'forget ' . self::QUOTE_ID], $this->journal);
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
     * A placement that returns leaves the record alone, whatever it answered: the order may only come later, from a
     * request with no proof, and that order must find the cart recorded.
     *
     * @dataProvider answers
     * @param mixed $answer What the placement returns
     * @return void
     */
    public function testAPlacementThatReturnsLeavesTheRecord(mixed $answer): void
    {
        $this->recordingRecognition();
        $this->records->expects(self::never())->method('forgetOrderless');

        self::assertSame($answer, $this->model()->place(self::MASKED_ID, static fn (): mixed => $answer));
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function answers(): array
    {
        return [
            'an order id' => [self::PLACEMENT_ANSWER],
            'a redirect to pay' => [['redirect_url' => 'https://pay.example/1']],
            'nothing' => [null],
        ];
    }

    /**
     * A checkout that wraps its placement, by the masked id, around one that goes through the plugin, by the entity id:
     * the record is made once, by the outer call, and a failure forgets it once, in the outer call, passing the
     * failure on unchanged.
     *
     * @return void
     */
    public function testNestedCallsOnOneCartMakeOneRecordAndForgetItInTheOuterCall(): void
    {
        $this->recordKeepingRecognition();
        $this->recordingForgetting();
        $failure = new LocalizedException(__('The payment could not be started.'));
        $model = $this->model();

        try {
            $model->place(
                self::MASKED_ID,
                fn (): mixed => $model->place(self::QUOTE_ID, $this->placement($failure))
            );
            self::fail('A failed placement was reported as placed.');
        } catch (LocalizedException $passedOn) {
            self::assertSame($failure, $passedOn);
        }

        self::assertSame(1, $this->recordsMade);
        self::assertSame(
            ['recognise ' . self::QUOTE_ID, 'recognise ' . self::QUOTE_ID, 'place', 'forget ' . self::QUOTE_ID],
            $this->journal
        );
    }

    /**
     * Nested calls on a cart recorded before either started forget nothing: the record is another placement's.
     *
     * @return void
     */
    public function testNestedCallsOnACartAlreadyRecordedForgetNothing(): void
    {
        $this->recordKeepingRecognition();
        $this->records->expects(self::never())->method('forgetOrderless');
        $failure = new LocalizedException(__('The payment could not be started.'));
        $this->recorded = true;
        $model = $this->model();

        try {
            $model->place(
                self::MASKED_ID,
                fn (): mixed => $model->place(self::QUOTE_ID, $this->placement($failure))
            );
            self::fail('A failed placement was reported as placed.');
        } catch (LocalizedException $passedOn) {
            self::assertSame($failure, $passedOn);
        }

        self::assertSame(0, $this->recordsMade);
    }

    /**
     * Nested calls that succeed hand the inner placement's answer out through both, and forget nothing.
     *
     * @return void
     */
    public function testNestedCallsThatSucceedKeepTheRecordAndTheAnswer(): void
    {
        $this->recordKeepingRecognition();
        $this->records->expects(self::never())->method('forgetOrderless');
        $model = $this->model();

        $answer = $model->place(self::MASKED_ID, fn (): mixed => $model->place(self::QUOTE_ID, $this->placement()));

        self::assertSame(self::PLACEMENT_ANSWER, $answer);
        self::assertSame(1, $this->recordsMade);
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
        $this->recorded = $alreadyRecorded;

        try {
            $this->model(logger: $logger)->place(self::QUOTE_ID, $this->placement($failure));
        } catch (\Throwable $passedOn) {
            return $passedOn;
        }

        self::fail('A failed placement was reported as placed.');
    }

    /**
     * Let recognition record the cart and note it in the journal, once.
     *
     * @return void
     */
    private function recordingRecognition(): void
    {
        $this->recognition->expects(self::once())->method('recognise')->with(self::QUOTE_ID)
            ->willReturnCallback($this->recognise(...));
    }

    /**
     * Let recognition record the cart and note it in the journal, as often as it is asked.
     *
     * @return void
     */
    private function recordKeepingRecognition(): void
    {
        $this->recognition->method('recognise')->with(self::QUOTE_ID)->willReturnCallback($this->recognise(...));
    }

    /**
     * What the real recognition does to a record: it makes one where there is none, and otherwise keeps it.
     *
     * @param int $quoteId Cart (quote) entity id
     * @return void
     */
    private function recognise(int $quoteId): void
    {
        $this->journal[] = 'recognise ' . $quoteId;
        $this->recordsMade += $this->recorded ? 0 : 1;
        $this->recorded = true;
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
                $this->recorded = false;

                return true;
            });
    }

    /**
     * The placement being wrapped: notes itself in the journal, then answers or throws.
     *
     * @param \Throwable|null $failure What the placement throws; null when it succeeds
     * @return callable(): string
     */
    private function placement(?\Throwable $failure = null): callable
    {
        return function () use ($failure): string {
            $this->journal[] = 'place';
            if ($failure !== null) {
                throw $failure;
            }

            return self::PLACEMENT_ANSWER;
        };
    }

    /**
     * The model. A cart counts as a test cart once recognition has recorded it, as the real question does, so a model
     * that asked after recognising would take its own record for another placement's.
     *
     * @param bool $proofSent Whether a proof was sent with the request
     * @param TestCartsInterface|null $testCarts The question about the cart; answering as above when null
     * @param MaskedQuoteIdToQuoteIdInterface|null $quoteIds The masked-id lookup; the test's own when null
     * @param LoggerInterface|null $logger Where a record that could not be forgotten is reported
     * @return TestCartPlacement
     */
    private function model(
        bool $proofSent = true,
        ?TestCartsInterface $testCarts = null,
        ?MaskedQuoteIdToQuoteIdInterface $quoteIds = null,
        ?LoggerInterface $logger = null
    ): TestCartPlacement {
        $requestProof = $this->createStub(RequestProofInterface::class);
        $requestProof->method('isSent')->willReturn($proofSent);

        if ($testCarts === null) {
            $testCarts = $this->createStub(TestCartsInterface::class);
            $testCarts->method('isTestCart')->willReturnCallback(fn (): bool => $this->recorded);
        }

        return new TestCartPlacement(
            $requestProof,
            $this->recognition,
            $testCarts,
            $this->records,
            $quoteIds ?? $this->quoteIds,
            $logger ?? $this->createMock(LoggerInterface::class)
        );
    }
}
