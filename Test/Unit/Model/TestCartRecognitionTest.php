<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\TestOrder\Test\Unit\Model;

use Hryvinskyi\TestOrder\Api\CartOrdersInterface;
use Hryvinskyi\TestOrder\Api\RecognitionGuardInterface;
use Hryvinskyi\TestOrder\Api\TestCartRecordsInterface;
use Hryvinskyi\TestOrder\Domain\TestOrderAction;
use Hryvinskyi\TestOrder\Domain\TestOrderProof;
use Hryvinskyi\TestOrder\Model\TestCartRecognition;
use Hryvinskyi\TestOrder\Test\Unit\Fixture\BuildsRequestProof;
use Hryvinskyi\TestOrder\Test\Unit\Fixture\InMemoryTestOrders;
use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Phrase;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Api\Data\CartInterface;
use Magento\Quote\Model\QuoteIdToMaskedQuoteIdInterface;
use Magento\Sales\Api\Data\OrderInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * With a valid placing proof on an open guest cart with no ordinary order, in a store no guard objects to, the cart is
 * recorded as a test cart; with any other proof, on a signed-in customer's cart, on a closed cart, on a cart with an
 * ordinary order, or against a guard's refusal, recognition refuses and records nothing.
 */
class TestCartRecognitionTest extends TestCase
{
    use BuildsRequestProof;

    private const int QUOTE_ID = 4711;

    private const int STORE_ID = 3;

    private const string MASKED_ID = 'f1Kq0wzWbG2s8yQkS3n1Xr7cV5mT9pLd';

    private const string GUEST_ONLY = 'A test order can only be placed from a guest cart, so the order was not placed.';

    private const string NOT_VERIFIED
        = 'The test-order proof sent with this order could not be verified, so the order was not placed.';

    private const string NOT_OPEN
        = 'A test order can only be placed from an open cart that has no ordinary order, so the order was not placed.';

    /**
     * @var TestCartRecordsInterface&MockObject
     */
    private TestCartRecordsInterface&MockObject $records;

    /**
     * @inheritDoc
     */
    protected function setUp(): void
    {
        $this->records = $this->createMock(TestCartRecordsInterface::class);
    }

    /**
     * A fresh placing proof made with the secret over the guest cart's masked id records the cart.
     *
     * @return void
     */
    public function testAValidProofOnAGuestCartRecordsTheCart(): void
    {
        $this->records->expects(self::once())->method('recognise')->with(self::QUOTE_ID);

        $this->recognition(self::signProof(TestOrderAction::Place, self::MASKED_ID, issuedAt: self::PROOF_NOW - 60))
            ->recognise(self::QUOTE_ID);
    }

    /**
     * A proof that cannot be verified refuses and records nothing.
     *
     * @dataProvider unverifiableProofs
     * @param string $proof Proof as sent
     * @return void
     */
    public function testAProofThatCannotBeVerifiedIsRefused(string $proof): void
    {
        $this->expectRefusal(self::NOT_VERIFIED, $this->recognition($proof));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function unverifiableProofs(): array
    {
        return [
            'empty header' => [''],
            'malformed' => ['not-a-proof'],
            'made with another secret' => [
                self::signProof(TestOrderAction::Place, self::MASKED_ID, 'another-test-order-secret-of-forty-chars'),
            ],
            'made for another cart' => [self::signProof(TestOrderAction::Place, 'zzKq0wzWbG2s8yQkS3n1Xr7cV5mT9pLd')],
            'made over the cart id instead of the masked id' => [
                self::signProof(TestOrderAction::Place, (string)self::QUOTE_ID),
            ],
            'made for abandoning the cart' => [self::signProof(TestOrderAction::Abandon, self::MASKED_ID)],
            'made for the recognition probe' => [self::signProof(TestOrderAction::Recognise, self::MASKED_ID)],
            'stale' => [
                self::signProof(
                    TestOrderAction::Place,
                    self::MASKED_ID,
                    issuedAt: self::PROOF_NOW - TestOrderProof::FRESHNESS_SECONDS - 1
                ),
            ],
            'made for later' => [
                self::signProof(
                    TestOrderAction::Place,
                    self::MASKED_ID,
                    issuedAt: self::PROOF_NOW + TestOrderProof::FRESHNESS_SECONDS + 1
                ),
            ],
        ];
    }

    /**
     * While the shop has no secret, even a proof that would otherwise be valid is refused.
     *
     * @return void
     */
    public function testWithoutASecretEveryProofIsRefused(): void
    {
        $this->expectRefusal(
            self::NOT_VERIFIED,
            $this->recognition(self::signProof(TestOrderAction::Place, self::MASKED_ID), secret: null)
        );
    }

    /**
     * A cart without a masked id is not a guest cart: a proof on it is refused.
     *
     * @return void
     */
    public function testAProofOnACartWithoutAMaskedIdIsRefused(): void
    {
        $this->expectRefusal(
            self::GUEST_ONLY,
            $this->recognition(self::signProof(TestOrderAction::Place, ''), maskedId: '')
        );
    }

    /**
     * A signed-in customer's cart that has a masked id is still refused, even with a proof valid for that id.
     *
     * @return void
     */
    public function testAProofOnASignedInCustomersCartIsRefused(): void
    {
        $this->expectRefusal(
            self::GUEST_ONLY,
            $this->recognition(self::signProof(TestOrderAction::Place, self::MASKED_ID), customerId: 12)
        );
    }

    /**
     * A recognition guard that objects in the cart's store refuses a proof that is valid, with the guard's own reason;
     * the guard is asked about the cart's store.
     *
     * @return void
     */
    public function testAGuardsRefusalIsRefusedWithItsReason(): void
    {
        $guard = $this->createMock(RecognitionGuardInterface::class);
        $guard->expects(self::once())->method('refusalFor')->with(self::STORE_ID)
            ->willReturn(new Phrase('Payments here run on live keys.'));

        $this->expectRefusal(
            'Payments here run on live keys.',
            $this->recognition(self::signProof(TestOrderAction::Place, self::MASKED_ID), guard: $guard)
        );
    }

    /**
     * A guard is never asked when the proof cannot be verified: whoever sends a wrong proof learns nothing about the
     * store.
     *
     * @return void
     */
    public function testTheGuardIsNotAskedForAProofThatCannotBeVerified(): void
    {
        $guard = $this->createMock(RecognitionGuardInterface::class);
        $guard->expects(self::never())->method('refusalFor');

        $this->expectRefusal(self::NOT_VERIFIED, $this->recognition('not-a-proof', guard: $guard));
    }

    /**
     * A closed cart is refused, whether or not its order is found: that order must not become a test order. The guard
     * is not asked.
     *
     * @return void
     */
    public function testAClosedCartIsRefusedBeforeTheGuardIsAsked(): void
    {
        $guard = $this->createMock(RecognitionGuardInterface::class);
        $guard->expects(self::never())->method('refusalFor');

        $this->expectRefusal(
            self::NOT_OPEN,
            $this->recognition(
                self::signProof(TestOrderAction::Place, self::MASKED_ID),
                guard: $guard,
                isActive: false
            )
        );
    }

    /**
     * A closed cart answers a wrong proof like any other cart, so a wrong proof learns nothing about the cart.
     *
     * @return void
     */
    public function testAClosedCartAnswersAWrongProofAsAnyOtherCart(): void
    {
        $this->expectRefusal(self::NOT_VERIFIED, $this->recognition('not-a-proof', isActive: false));
    }

    /**
     * An open cart with an order that is no test cart is refused: that order is an ordinary one.
     *
     * @return void
     */
    public function testACartWithAnOrdinaryOrderIsRefused(): void
    {
        $this->expectRefusal(
            self::NOT_OPEN,
            $this->recognition(
                self::signProof(TestOrderAction::Place, self::MASKED_ID),
                orders: [$this->createStub(OrderInterface::class)]
            )
        );
    }

    /**
     * A test cart with an order may place again: a placement can save an order and then fail, and the retry places
     * another.
     *
     * @return void
     */
    public function testATestCartWithAnOrderMayPlaceAgain(): void
    {
        $this->records->expects(self::once())->method('recognise')->with(self::QUOTE_ID);

        $this->recognition(
            self::signProof(TestOrderAction::Place, self::MASKED_ID),
            orders: [$this->createStub(OrderInterface::class)],
            testQuoteIds: [self::QUOTE_ID]
        )->recognise(self::QUOTE_ID);
    }

    /**
     * Expect recognition to refuse with the message, with nothing recorded.
     *
     * @param string $message Refusal shown to the caller
     * @param TestCartRecognition $recognition The recognition under test
     * @return void
     */
    private function expectRefusal(string $message, TestCartRecognition $recognition): void
    {
        $this->records->expects(self::never())->method('recognise');

        try {
            $recognition->recognise(self::QUOTE_ID);
            self::fail('The cart was not refused.');
        } catch (LocalizedException $refusal) {
            self::assertSame($message, $refusal->getMessage());
        }
    }

    /**
     * The recognition, with the real proof judging, parsing and verification.
     *
     * @param string|null $proof Proof sent with the request; null when none is
     * @param string|null $secret The shop's secret; null when it has none
     * @param string $maskedId The cart's masked id; empty when it has none
     * @param int|null $customerId Customer the cart belongs to; null for a guest cart
     * @param RecognitionGuardInterface|null $guard The guards; none objecting when null
     * @param bool $isActive Whether the cart is open
     * @param list<OrderInterface> $orders Orders placed from the cart
     * @param list<int> $testQuoteIds Carts already recorded as test carts
     * @return TestCartRecognition
     */
    private function recognition(
        ?string $proof,
        ?string $secret = self::PROOF_SECRET,
        string $maskedId = self::MASKED_ID,
        ?int $customerId = null,
        ?RecognitionGuardInterface $guard = null,
        bool $isActive = true,
        array $orders = [],
        array $testQuoteIds = []
    ): TestCartRecognition {
        $maskedQuoteIds = $this->createMock(QuoteIdToMaskedQuoteIdInterface::class);
        $maskedQuoteIds->method('execute')->with(self::QUOTE_ID)->willReturn($maskedId);

        $customer = $this->createMock(CustomerInterface::class);
        $customer->method('getId')->willReturn($customerId);
        $cart = $this->createMock(CartInterface::class);
        $cart->method('getCustomer')->willReturn($customer);
        $cart->method('getStoreId')->willReturn(self::STORE_ID);
        $cart->method('getIsActive')->willReturn($isActive);
        $carts = $this->createMock(CartRepositoryInterface::class);
        $carts->method('get')->with(self::QUOTE_ID)->willReturn($cart);

        if ($guard === null) {
            $guard = $this->createMock(RecognitionGuardInterface::class);
            $guard->method('refusalFor')->willReturn(null);
        }

        $cartOrders = $this->createStub(CartOrdersInterface::class);
        $cartOrders->method('ofCart')->willReturn($orders);

        return new TestCartRecognition(
            $this->requestProof($proof, $secret),
            $maskedQuoteIds,
            $carts,
            $guard,
            $this->records,
            $cartOrders,
            new InMemoryTestOrders($testQuoteIds)
        );
    }
}
