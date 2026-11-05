<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\TestOrder\Test\Unit\Model;

use Hryvinskyi\TestOrder\Api\CartGuardInterface;
use Hryvinskyi\TestOrder\Api\CartOrdersInterface;
use Hryvinskyi\TestOrder\Api\RecognitionGuardInterface;
use Hryvinskyi\TestOrder\Api\TestCartRecordsInterface;
use Hryvinskyi\TestOrder\Api\TestCustomersInterface;
use Hryvinskyi\TestOrder\Domain\TestOrderAction;
use Hryvinskyi\TestOrder\Domain\TestOrderProof;
use Hryvinskyi\TestOrder\Model\TestCartRecognition;
use Hryvinskyi\TestOrder\Test\Unit\Fixture\BuildsRequestProof;
use Hryvinskyi\TestOrder\Test\Unit\Fixture\InMemoryTestOrders;
use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Phrase;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Api\Data\PaymentInterface;
use Magento\Quote\Api\PaymentMethodManagementInterface;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\QuoteIdToMaskedQuoteIdInterface;
use Magento\Sales\Api\Data\OrderInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * With a valid placing proof on an open guest or test customer's cart with no ordinary order, in a store and on a cart no
 * guard objects to, the cart is recorded as a test cart; with any other proof, on the cart of a customer the deployment
 * does not name, on a closed cart, on a cart with an ordinary order, or against a guard's refusal, recognition refuses
 * and records nothing. Without a valid proof the refusal is the same whatever the cart is, or if it is none at all.
 */
class TestCartRecognitionTest extends TestCase
{
    use BuildsRequestProof;

    private const int QUOTE_ID = 4711;

    private const int STORE_ID = 3;

    private const string MASKED_ID = 'f1Kq0wzWbG2s8yQkS3n1Xr7cV5mT9pLd';

    private const string TEST_CUSTOMER = 'buyer@example.test';

    private const string NAMED_METHOD = 'method_the_call_names';

    private const string SAVED_METHOD = 'method_saved_on_the_cart';

    private const string OTHER_CUSTOMER = 'someone@example.test';

    private const string WRONG_OWNER
        = "A test order can only be placed from a guest cart or a test customer's cart, so the order was not placed.";

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
     * The proofs no cart can honour.
     *
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
     * A cart without a masked id has nothing a proof could be made over, so no proof can be valid for it.
     *
     * @return void
     */
    public function testAProofOnACartWithoutAMaskedIdIsRefused(): void
    {
        $this->expectRefusal(
            self::NOT_VERIFIED,
            $this->recognition(self::signProof(TestOrderAction::Place, ''), maskedId: '')
        );
    }

    /**
     * A named customer's cart without a masked id is refused too: the proof has nothing to be made over.
     *
     * @return void
     */
    public function testANamedCustomersCartWithoutAMaskedIdIsRefused(): void
    {
        $this->expectRefusal(
            self::NOT_VERIFIED,
            $this->recognition(
                self::signProof(TestOrderAction::Place, ''),
                maskedId: '',
                customerId: 12,
                customerEmail: self::TEST_CUSTOMER
            )
        );
    }

    /**
     * The cart of a customer the deployment names is recorded on a valid proof, and the name asked about is the
     * account's address.
     *
     * @return void
     */
    public function testAValidProofOnANamedCustomersCartRecordsTheCart(): void
    {
        $testCustomers = $this->createMock(TestCustomersInterface::class);
        $testCustomers->expects(self::once())->method('isTestCustomer')->with(self::TEST_CUSTOMER)->willReturn(true);
        $this->records->expects(self::once())->method('recognise')->with(self::QUOTE_ID);

        $this->recognition(
            self::signProof(TestOrderAction::Place, self::MASKED_ID),
            customerId: 12,
            customerEmail: self::TEST_CUSTOMER,
            testCustomers: $testCustomers
        )->recognise(self::QUOTE_ID);
    }

    /**
     * The cart of a customer the deployment does not name is refused, even with a proof valid for its masked id.
     *
     * @return void
     */
    public function testAValidProofOnAnUnnamedCustomersCartIsRefused(): void
    {
        $this->expectRefusal(
            self::WRONG_OWNER,
            $this->recognition(
                self::signProof(TestOrderAction::Place, self::MASKED_ID),
                customerId: 12,
                customerEmail: self::OTHER_CUSTOMER
            )
        );
    }

    /**
     * A junk proof gets one answer whatever the id points at: the refusal must not tell a missing cart from a guest's or
     * a customer's, or anyone could map which carts exist and who owns them.
     *
     * @dataProvider cartsAJunkProofLearnsNothingAbout
     * @param bool $cartExists Whether the cart exists
     * @param int|null $customerId Customer the cart belongs to; null for a guest cart
     * @param string|null $customerEmail The e-mail of the customer's account
     * @return void
     */
    public function testAJunkProofGetsTheSameRefusalWhateverTheCart(
        bool $cartExists,
        ?int $customerId,
        ?string $customerEmail
    ): void {
        $this->expectRefusal(
            self::NOT_VERIFIED,
            $this->recognition(
                'not-a-proof',
                customerId: $customerId,
                customerEmail: $customerEmail,
                cartExists: $cartExists
            )
        );
    }

    /**
     * The carts a junk proof must not be able to tell apart.
     *
     * @return array<string, array{bool, int|null, string|null}>
     */
    public static function cartsAJunkProofLearnsNothingAbout(): array
    {
        return [
            'no such cart' => [false, null, null],
            "a guest's cart" => [true, null, null],
            "an unnamed customer's cart" => [true, 12, self::OTHER_CUSTOMER],
        ];
    }

    /**
     * A proof that cannot be verified never reaches the cart, so nothing about it can show in the answer.
     *
     * @return void
     */
    public function testAJunkProofNeverLooksAtTheCart(): void
    {
        $carts = $this->createMock(CartRepositoryInterface::class);
        $carts->expects(self::never())->method('get');

        $this->expectRefusal(self::NOT_VERIFIED, $this->recognition('not-a-proof', carts: $carts));
    }

    /**
     * The address a checkout wrote on the cart from what the visitor typed names nobody: only the account's does.
     *
     * @return void
     */
    public function testTheCartsOwnEmailDoesNotMakeItsOwnerATestCustomer(): void
    {
        $this->expectRefusal(
            self::WRONG_OWNER,
            $this->recognition(
                self::signProof(TestOrderAction::Place, self::MASKED_ID),
                customerId: 12,
                customerEmail: self::OTHER_CUSTOMER,
                cartEmail: self::TEST_CUSTOMER
            )
        );
    }

    /**
     * A named customer's cart is refused for a proof that cannot be verified as any other cart is.
     *
     * @return void
     */
    public function testANamedCustomersCartWithABadProofIsRefusedAsABadProof(): void
    {
        $this->expectRefusal(
            self::NOT_VERIFIED,
            $this->recognition('not-a-proof', customerId: 12, customerEmail: self::TEST_CUSTOMER)
        );
    }

    /**
     * A named customer's closed cart is refused: the order placed from it must not become a test order.
     *
     * @return void
     */
    public function testANamedCustomersClosedCartIsRefused(): void
    {
        $this->expectRefusal(
            self::NOT_OPEN,
            $this->recognition(
                self::signProof(TestOrderAction::Place, self::MASKED_ID),
                customerId: 12,
                customerEmail: self::TEST_CUSTOMER,
                isActive: false
            )
        );
    }

    /**
     * A named customer's open cart with an ordinary order is refused: that order is the customer's own.
     *
     * @return void
     */
    public function testANamedCustomersCartWithAnOrdinaryOrderIsRefused(): void
    {
        $this->expectRefusal(
            self::NOT_OPEN,
            $this->recognition(
                self::signProof(TestOrderAction::Place, self::MASKED_ID),
                customerId: 12,
                customerEmail: self::TEST_CUSTOMER,
                orders: [$this->createStub(OrderInterface::class)]
            )
        );
    }

    /**
     * A guest cart names no customer, so no list of customers is asked about it.
     *
     * @return void
     */
    public function testAGuestCartIsNotAskedAboutAsACustomer(): void
    {
        $testCustomers = $this->createMock(TestCustomersInterface::class);
        $testCustomers->expects(self::never())->method('isTestCustomer');
        $this->records->expects(self::once())->method('recognise')->with(self::QUOTE_ID);

        $this->recognition(self::signProof(TestOrderAction::Place, self::MASKED_ID), testCustomers: $testCustomers)
            ->recognise(self::QUOTE_ID);
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
     * The guard that sees the cart refuses a valid proof with its own reason, about the cart being placed, and nothing is
     * recorded.
     *
     * @return void
     */
    public function testACartGuardsRefusalIsRefusedWithItsReason(): void
    {
        $cartGuard = $this->createMock(CartGuardInterface::class);
        $cartGuard->expects(self::once())->method('refusalForCart')->with(self::isInstanceOf(Quote::class))
            ->willReturn(new Phrase('This cart pays on live keys.'));

        $this->expectRefusal(
            'This cart pays on live keys.',
            $this->recognition(self::signProof(TestOrderAction::Place, self::MASKED_ID), cartGuard: $cartGuard)
        );
    }

    /**
     * The guard that sees the cart is never asked without a valid proof: whoever sends a wrong one learns nothing from it.
     *
     * @return void
     */
    public function testTheCartGuardIsNotAskedForAProofThatCannotBeVerified(): void
    {
        $cartGuard = $this->createMock(CartGuardInterface::class);
        $cartGuard->expects(self::never())->method('refusalForCart');

        $this->expectRefusal(self::NOT_VERIFIED, $this->recognition('not-a-proof', cartGuard: $cartGuard));
    }

    /**
     * The store's guards come first: a store they refuse never gets as far as the cart's.
     *
     * @return void
     */
    public function testTheCartGuardIsNotAskedOnceAStoreGuardRefuses(): void
    {
        $guard = $this->createMock(RecognitionGuardInterface::class);
        $guard->method('refusalFor')->willReturn(new Phrase('Payments here run on live keys.'));
        $cartGuard = $this->createMock(CartGuardInterface::class);
        $cartGuard->expects(self::never())->method('refusalForCart');
        $paymentMethods = $this->createMock(PaymentMethodManagementInterface::class);
        $paymentMethods->expects(self::never())->method('get');

        $this->expectRefusal(
            'Payments here run on live keys.',
            $this->recognition(
                self::signProof(TestOrderAction::Place, self::MASKED_ID),
                guard: $guard,
                cartGuard: $cartGuard,
                paymentMethods: $paymentMethods
            )
        );
    }

    /**
     * A cart with no objection is recorded, and the guard was asked about it.
     *
     * @return void
     */
    public function testACartNoCartGuardObjectsToIsRecorded(): void
    {
        $cartGuard = $this->createMock(CartGuardInterface::class);
        $cartGuard->expects(self::once())->method('refusalForCart')->willReturn(null);
        $this->records->expects(self::once())->method('recognise')->with(self::QUOTE_ID);

        $this->recognition(self::signProof(TestOrderAction::Place, self::MASKED_ID), cartGuard: $cartGuard)
            ->recognise(self::QUOTE_ID);
    }

    /**
     * The method the placement names is what the guards see, and it wins over the cart's own, which is not even read: the
     * placement will replace it.
     *
     * @return void
     */
    public function testTheMethodTheCallNamesWinsOverTheCartsOwn(): void
    {
        $paymentMethods = $this->createMock(PaymentMethodManagementInterface::class);
        $paymentMethods->expects(self::never())->method('get');
        $cartGuard = $this->createMock(CartGuardInterface::class);
        $cartGuard->expects(self::once())->method('refusalForCart')
            ->with(self::isInstanceOf(Quote::class), self::NAMED_METHOD)->willReturn(null);

        $this->recognition(
            self::signProof(TestOrderAction::Place, self::MASKED_ID),
            cartGuard: $cartGuard,
            paymentMethods: $paymentMethods
        )->recognise(self::QUOTE_ID, self::NAMED_METHOD);
    }

    /**
     * A placement that names no method is paid with the cart's own, so that is what the guards see, read for this cart.
     *
     * @return void
     */
    public function testTheCartsOwnMethodIsUsedWhenTheCallNamesNone(): void
    {
        $saved = $this->createStub(PaymentInterface::class);
        $saved->method('getMethod')->willReturn(self::SAVED_METHOD);
        $paymentMethods = $this->createMock(PaymentMethodManagementInterface::class);
        $paymentMethods->expects(self::once())->method('get')->with(self::QUOTE_ID)->willReturn($saved);
        $cartGuard = $this->createMock(CartGuardInterface::class);
        $cartGuard->expects(self::once())->method('refusalForCart')
            ->with(self::isInstanceOf(Quote::class), self::SAVED_METHOD)->willReturn(null);

        $this->recognition(
            self::signProof(TestOrderAction::Place, self::MASKED_ID),
            cartGuard: $cartGuard,
            paymentMethods: $paymentMethods
        )->recognise(self::QUOTE_ID);
    }

    /**
     * With no method named and no payment saved the guards are told null, and the cart is not refused for it.
     *
     * @return void
     */
    public function testNeitherACallNorACartNamingAMethodGivesTheGuardsNull(): void
    {
        $paymentMethods = $this->createMock(PaymentMethodManagementInterface::class);
        $paymentMethods->method('get')->willReturn(null);
        $cartGuard = $this->createMock(CartGuardInterface::class);
        $cartGuard->expects(self::once())->method('refusalForCart')
            ->with(self::isInstanceOf(Quote::class), null)->willReturn(null);
        $this->records->expects(self::once())->method('recognise')->with(self::QUOTE_ID);

        $this->recognition(
            self::signProof(TestOrderAction::Place, self::MASKED_ID),
            cartGuard: $cartGuard,
            paymentMethods: $paymentMethods
        )->recognise(self::QUOTE_ID);
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
     * @param string|null $customerEmail The e-mail of the customer's account
     * @param string|null $cartEmail The cart's own `customer_email`
     * @param TestCustomersInterface|null $testCustomers The test customers; only TEST_CUSTOMER when null
     * @param RecognitionGuardInterface|null $guard The guards; none objecting when null
     * @param bool $isActive Whether the cart is open
     * @param list<OrderInterface> $orders Orders placed from the cart
     * @param list<int> $testQuoteIds Carts already recorded as test carts
     * @param bool $cartExists Whether the cart exists
     * @param CartRepositoryInterface|null $carts The carts; a repository of this one cart when null
     * @param CartGuardInterface|null $cartGuard The guards that see the cart; none objecting when null
     * @param PaymentMethodManagementInterface|null $paymentMethods The saved methods; none saved when null
     * @return TestCartRecognition
     */
    private function recognition(
        ?string $proof,
        ?string $secret = self::PROOF_SECRET,
        string $maskedId = self::MASKED_ID,
        ?int $customerId = null,
        ?string $customerEmail = null,
        ?string $cartEmail = null,
        ?TestCustomersInterface $testCustomers = null,
        ?RecognitionGuardInterface $guard = null,
        bool $isActive = true,
        array $orders = [],
        array $testQuoteIds = [],
        bool $cartExists = true,
        ?CartRepositoryInterface $carts = null,
        ?CartGuardInterface $cartGuard = null,
        ?PaymentMethodManagementInterface $paymentMethods = null
    ): TestCartRecognition {
        $maskedQuoteIds = $this->createMock(QuoteIdToMaskedQuoteIdInterface::class);
        $maskedLookup = $maskedQuoteIds->method('execute')->with(self::QUOTE_ID);
        if ($cartExists) {
            $maskedLookup->willReturn($maskedId);
        } else {
            $maskedLookup->willThrowException(new NoSuchEntityException(__('No such cart.')));
        }

        $customer = $this->createMock(CustomerInterface::class);
        $customer->method('getId')->willReturn($customerId);
        $customer->method('getEmail')->willReturn($customerEmail);
        $cart = $this->createPartialMock(Quote::class, ['getCustomer', 'getStoreId', 'getIsActive']);
        $cart->setData('customer_email', $cartEmail);
        $cart->method('getCustomer')->willReturn($customer);
        $cart->method('getStoreId')->willReturn(self::STORE_ID);
        $cart->method('getIsActive')->willReturn($isActive);
        if ($carts === null) {
            $carts = $this->createMock(CartRepositoryInterface::class);
            $carts->method('get')->with(self::QUOTE_ID)->willReturn($cart);
        }

        if ($guard === null) {
            $guard = $this->createMock(RecognitionGuardInterface::class);
            $guard->method('refusalFor')->willReturn(null);
        }

        $cartOrders = $this->createStub(CartOrdersInterface::class);
        $cartOrders->method('ofCart')->willReturn($orders);

        if ($testCustomers === null) {
            $testCustomers = $this->createStub(TestCustomersInterface::class);
            $testCustomers->method('isTestCustomer')->willReturnCallback(
                static fn (string $email): bool => $email === self::TEST_CUSTOMER
            );
        }

        return new TestCartRecognition(
            $this->requestProof($proof, $secret),
            $maskedQuoteIds,
            $carts,
            $guard,
            $this->records,
            $cartOrders,
            new InMemoryTestOrders($testQuoteIds),
            $testCustomers,
            $cartGuard ?? $this->createStub(CartGuardInterface::class),
            $paymentMethods ?? $this->createStub(PaymentMethodManagementInterface::class)
        );
    }
}
