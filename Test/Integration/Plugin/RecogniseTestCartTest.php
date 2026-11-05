<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\TestOrder\Test\Integration\Plugin;

use Laminas\Http\Header\HeaderInterface;
use Laminas\Http\Headers;
use Laminas\Stdlib\Parameters;
use Hryvinskyi\TestOrder\Api\CartGuardInterface;
use Hryvinskyi\TestOrder\Api\CartOrdersInterface;
use Hryvinskyi\TestOrder\Api\Data\TestOrderInterface;
use Hryvinskyi\TestOrder\Api\RecognitionGuardInterface;
use Hryvinskyi\TestOrder\Api\TestCartRecordsInterface;
use Hryvinskyi\TestOrder\Api\TestOrderAbandonmentInterface;
use Hryvinskyi\TestOrder\Api\TestOrderRepositoryInterface;
use Hryvinskyi\TestOrder\Api\TestOrdersInterface;
use Hryvinskyi\TestOrder\Domain\TestOrderAction;
use Hryvinskyi\TestOrder\Model\CartGuardPool;
use Hryvinskyi\TestOrder\Model\ProofSource\RequestProofSource;
use Hryvinskyi\TestOrder\Model\RecognitionGuardPool;
use Hryvinskyi\TestOrder\Model\TestCartRecognition;
use Hryvinskyi\TestOrder\Model\TestOrderRepository;
use Hryvinskyi\TestOrder\Plugin\RecogniseTestCart;
use Hryvinskyi\TestOrder\Test\Integration\CartRows;
use Hryvinskyi\TestOrder\Test\Integration\OrderRows;
use Magento\Framework\App\Area;
use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Phrase;
use Magento\Quote\Api\CartManagementInterface;
use Magento\Quote\Api\Data\CartInterface;
use Magento\Quote\Api\Data\PaymentInterface;
use Magento\Quote\Model\CartLockedException;
use Magento\Quote\Model\CartMutexInterface;
use Magento\Quote\Model\QuoteIdToMaskedQuoteIdInterface;
use Magento\Sales\Model\Order;
use Magento\TestFramework\Helper\Bootstrap;
use Magento\TestFramework\ObjectManager;
use PHPUnit\Framework\TestCase;

/**
 * Recognition through the real order-placement service of the REST and GraphQL areas, with the real header, the real
 * secret from the deployment configuration and the real record.
 *
 * Each cart is open and has no items, so placement stops in the core right after recognition with the core's own
 * message: that message proves the placement went on, and no order is created. A refusal is the plugin's message
 * instead. Because the placement fails, a record it made stays only where an order of the cart exists — as when the
 * payment provider saved the order before placement failed — and only a test cart may place with an order, so the tests
 * that look for the record recognise the cart and give it such an order first, and read the time moved by the placement.
 *
 * @magentoDbIsolation enabled
 * @magentoAppIsolation enabled
 */
class RecogniseTestCartTest extends TestCase
{
    private const string SECRET = 'an-integration-test-order-secret-0123456789';

    private const string MASKED_ID = 'TestOrderRecognition0000000000aa';

    private const string OVERRIDE_VARIABLE = 'MAGENTO_DC__OVERRIDE';

    private const string PROOF_SERVER_PARAMETER = 'HTTP_X_TEST_ORDER_PROOF';

    private const string LONG_AGO = '2026-01-01 00:00:00';

    private const string FIXTURE_CUSTOMER = 'customer@example.com';

    private const string FIXTURE_CUSTOMER_ORDER = '55555555';

    private const string THE_CORE_STOPPED
        = 'A server error stopped your order from being placed. Please try to place your order again.';

    private const string WRONG_OWNER
        = "A test order can only be placed from a guest cart or a test customer's cart, so the order was not placed.";

    private const string NOT_VERIFIED
        = 'The test-order proof sent with this order could not be verified, so the order was not placed.';

    private const string NOT_OPEN
        = 'A test order can only be placed from an open cart that has no ordinary order, so the order was not placed.';

    private const string OTHER_MASKED_ID = 'TestOrderRecognition0000000000bb';

    private const int NO_SUCH_CART = 987654321;

    private const string METHOD_SAVED_ON_THE_FIXTURE_CART = 'checkmo';

    private const string METHOD_ONLY_THE_CALL_NAMES = 'banktransfer';

    /**
     * @var string|false The override variable as it was before the test
     */
    private string|false $previousOverride = false;

    /**
     * @inheritDoc
     */
    protected function setUp(): void
    {
        $this->previousOverride = getenv(self::OVERRIDE_VARIABLE);
    }

    /**
     * @inheritDoc
     */
    protected function tearDown(): void
    {
        putenv(
            $this->previousOverride === false
                ? self::OVERRIDE_VARIABLE
                : self::OVERRIDE_VARIABLE . '=' . $this->previousOverride
        );
        $this->deploymentConfig()->resetData();
        $this->sendProof(null);
    }

    /**
     * A REST placement with a valid proof records the guest cart before the core places anything, and the record
     * outlives the failed placement because an order of the cart exists: a test cart may place again.
     *
     * @magentoAppArea webapi_rest
     * @return void
     */
    public function testARestPlacementWithAValidProofRecognisesTheCart(): void
    {
        $this->configureSecret(self::SECRET);
        $quoteId = $this->testCartWithAnOrder();
        $this->sendProof($this->sign(self::SECRET, self::MASKED_ID));

        $this->assertPlacementWentOn($this->place($quoteId));
        self::assertNotSame(self::LONG_AGO, $this->repository()->getByQuoteId($quoteId)->getRecognisedAt());
    }

    /**
     * A GraphQL placement with a valid proof records the guest cart too.
     *
     * @magentoAppArea graphql
     * @return void
     */
    public function testAGraphQlPlacementWithAValidProofRecognisesTheCart(): void
    {
        $this->configureSecret(self::SECRET);
        $quoteId = $this->testCartWithAnOrder();
        $this->sendProof($this->sign(self::SECRET, self::MASKED_ID));

        $this->assertPlacementWentOn($this->place($quoteId));
        self::assertNotSame(self::LONG_AGO, $this->repository()->getByQuoteId($quoteId)->getRecognisedAt());
    }

    /**
     * A placement that fails after recognition, with no order of the cart left behind, forgets the cart, and the
     * core's own failure reaches the caller unchanged.
     *
     * @magentoAppArea webapi_rest
     * @return void
     */
    public function testAFailedPlacementWithoutAnOrderForgetsTheCart(): void
    {
        $this->configureSecret(self::SECRET);
        $quoteId = CartRows::insertOpenGuestCart(self::MASKED_ID);
        $this->sendProof($this->sign(self::SECRET, self::MASKED_ID));

        $this->assertPlacementWentOn($this->place($quoteId));
        $this->assertNotRecorded($quoteId);
    }

    /**
     * A recognition guard's objection refuses a placement whose proof is valid, with the guard's reason, before the
     * core is reached.
     *
     * @magentoAppArea webapi_rest
     * @return void
     */
    public function testAGuardsRefusalRefusesThePlacement(): void
    {
        $this->configureSecret(self::SECRET);
        $quoteId = CartRows::insertOpenGuestCart(self::MASKED_ID);
        $this->sendProof($this->sign(self::SECRET, self::MASKED_ID));
        $this->guardRefusing('Test orders are not placed in this store.');

        try {
            $this->assertRefused('Test orders are not placed in this store.', $this->place($quoteId));
            $this->assertNotRecorded($quoteId);
        } finally {
            $this->objectManager()->removeSharedInstance(RecognitionGuardPool::class);
            $this->objectManager()->removeSharedInstance(TestCartRecognition::class);
        }
    }

    /**
     * A cart guard in the container's own pool refuses a real cart that could be placed: the reason is the guard's, about
     * that very cart, nothing is recorded, and the placement never ran, so no order exists.
     *
     * @magentoDataFixture Magento/Sales/_files/guest_quote_with_addresses.php
     * @magentoAppArea webapi_rest
     * @return void
     */
    public function testACartGuardInTheRealPoolRefusesARealCart(): void
    {
        self::assertInstanceOf(CartGuardPool::class, $this->objectManager()->get(CartGuardInterface::class));
        $this->configureSecret(self::SECRET);
        [$quoteId, $maskedId] = $this->fixtureCart();
        $this->sendProof($this->sign(self::SECRET, $maskedId));
        $this->cartGuardRefusing($quoteId, 'This cart pays on live keys.');

        try {
            $this->assertRefused('This cart pays on live keys.', $this->place($quoteId));
            $this->assertNotRecorded($quoteId);
            self::assertSame([], $this->cartOrders()->ofCart($quoteId));
        } finally {
            $this->objectManager()->removeSharedInstance(CartGuardPool::class);
            $this->objectManager()->removeSharedInstance(TestCartRecognition::class);
        }
    }

    /**
     * A method named only in the placement call is what a guard judges: the cart still holds another one, and the core
     * would place the order on the method the call names. The refusal comes first, nothing is recorded and no order
     * exists.
     *
     * @magentoDataFixture Magento/Sales/_files/guest_quote_with_addresses.php
     * @magentoConfigFixture default_store payment/banktransfer/active 1
     * @magentoAppArea webapi_rest
     * @return void
     */
    public function testACartGuardRefusesAMethodNamedOnlyInTheCall(): void
    {
        $this->configureSecret(self::SECRET);
        [$quoteId, $maskedId] = $this->fixtureCart();
        $this->sendProof($this->sign(self::SECRET, $maskedId));
        $this->cartGuardRefusingMethod(self::METHOD_ONLY_THE_CALL_NAMES, 'This method takes real money.');
        $payment = $this->objectManager()->create(PaymentInterface::class);
        self::assertInstanceOf(PaymentInterface::class, $payment);
        $payment->setMethod(self::METHOD_ONLY_THE_CALL_NAMES);

        try {
            $stopped = null;
            try {
                $this->cartManagement()->placeOrder($quoteId, $payment);
            } catch (\Throwable $refusal) {
                $stopped = $refusal;
            }
            self::assertInstanceOf(LocalizedException::class, $stopped, 'The order was placed.');
            $this->assertRefused('This method takes real money.', $stopped);
            $this->assertNotRecorded($quoteId);
            self::assertSame([], $this->cartOrders()->ofCart($quoteId));
        } finally {
            $this->objectManager()->removeSharedInstance(CartGuardPool::class);
            $this->objectManager()->removeSharedInstance(TestCartRecognition::class);
        }
    }

    /**
     * A placement that names no method is paid with the one saved on the cart, and the real payment contract tells a
     * guard which that is.
     *
     * @magentoDataFixture Magento/Sales/_files/guest_quote_with_addresses.php
     * @magentoAppArea webapi_rest
     * @return void
     */
    public function testACartGuardSeesTheMethodSavedOnTheCart(): void
    {
        $this->configureSecret(self::SECRET);
        [$quoteId, $maskedId] = $this->fixtureCart();
        $this->sendProof($this->sign(self::SECRET, $maskedId));
        $this->cartGuardRefusingMethod(self::METHOD_SAVED_ON_THE_FIXTURE_CART, 'This method takes real money.');

        try {
            $this->assertRefused('This method takes real money.', $this->place($quoteId));
            $this->assertNotRecorded($quoteId);
        } finally {
            $this->objectManager()->removeSharedInstance(CartGuardPool::class);
            $this->objectManager()->removeSharedInstance(TestCartRecognition::class);
        }
    }

    /**
     * A junk proof is answered alike for a cart that does not exist, a guest's cart and an unnamed customer's cart, and
     * nothing is recorded, so the refusal tells a visitor nothing about any of them.
     *
     * @magentoDataFixture Magento/Checkout/_files/customer_quote_ready_for_order.php
     * @magentoAppArea webapi_rest
     * @return void
     */
    public function testAJunkProofIsAnsweredAlikeWhateverTheCart(): void
    {
        $this->configureSecret(self::SECRET, ['buyer@example.test']);
        $customersCart = $this->customerCart();
        $guestsCart = CartRows::insertOpenGuestCart(self::OTHER_MASKED_ID);
        $this->sendProof('not-a-proof');

        foreach ([self::NO_SUCH_CART, $guestsCart, $customersCart] as $quoteId) {
            $this->assertRefused(self::NOT_VERIFIED, $this->place($quoteId));
            $this->assertNotRecorded($quoteId);
        }
    }

    /**
     * A proof made for another action — abandoning the same cart — refuses the placement.
     *
     * @magentoAppArea webapi_rest
     * @return void
     */
    public function testAProofForAnotherActionIsRefused(): void
    {
        $this->configureSecret(self::SECRET);
        $quoteId = CartRows::insertOpenGuestCart(self::MASKED_ID);
        $this->sendProof($this->sign(self::SECRET, self::MASKED_ID, TestOrderAction::Abandon));

        $this->assertRefused(self::NOT_VERIFIED, $this->place($quoteId));
        $this->assertNotRecorded($quoteId);
    }

    /**
     * A REST placement without a proof goes on as an ordinary one and records nothing.
     *
     * @magentoAppArea webapi_rest
     * @return void
     */
    public function testARestPlacementWithoutAProofIsLeftAlone(): void
    {
        $this->configureSecret(self::SECRET);
        $quoteId = CartRows::insertOpenGuestCart(self::MASKED_ID);

        $this->assertPlacementWentOn($this->place($quoteId));
        $this->assertNotRecorded($quoteId);
    }

    /**
     * A proof made with another secret refuses the REST placement before the core is reached.
     *
     * @magentoAppArea webapi_rest
     * @return void
     */
    public function testARestPlacementWithAProofMadeWithAnotherSecretIsRefused(): void
    {
        $this->configureSecret(self::SECRET);
        $quoteId = CartRows::insertOpenGuestCart(self::MASKED_ID);
        $this->sendProof($this->sign('another-integration-test-order-secret-98765', self::MASKED_ID));

        $this->assertRefused(self::NOT_VERIFIED, $this->place($quoteId));
        $this->assertNotRecorded($quoteId);
    }

    /**
     * A malformed proof refuses the GraphQL placement.
     *
     * @magentoAppArea graphql
     * @return void
     */
    public function testAGraphQlPlacementWithAMalformedProofIsRefused(): void
    {
        $this->configureSecret(self::SECRET);
        $quoteId = CartRows::insertOpenGuestCart(self::MASKED_ID);
        $this->sendProof('not-a-proof');

        $this->assertRefused(self::NOT_VERIFIED, $this->place($quoteId));
        $this->assertNotRecorded($quoteId);
    }

    /**
     * An empty proof header is a proof that cannot be verified, never an ordinary order: the request object drops an
     * empty header, and a runner bug must not place a real order.
     *
     * @magentoAppArea webapi_rest
     * @return void
     */
    public function testAnEmptyProofHeaderRefusesThePlacement(): void
    {
        $this->configureSecret(self::SECRET);
        $quoteId = CartRows::insertOpenGuestCart(self::MASKED_ID);
        $request = Bootstrap::getObjectManager()->get(RequestInterface::class);
        self::assertInstanceOf(HttpRequest::class, $request);
        $request->setServer(new Parameters([self::PROOF_SERVER_PARAMETER => '']));

        try {
            $this->assertRefused(self::NOT_VERIFIED, $this->place($quoteId));
            $this->assertNotRecorded($quoteId);
        } finally {
            $request->setServer(new Parameters([]));
        }
    }

    /**
     * While the shop has no secret, a proof refuses the placement instead of placing an ordinary order.
     *
     * @magentoAppArea webapi_rest
     * @return void
     */
    public function testAProofIsRefusedWhileTheShopHasNoSecret(): void
    {
        $this->configureSecret(null);
        $quoteId = CartRows::insertOpenGuestCart(self::MASKED_ID);
        $this->sendProof($this->sign(self::SECRET, self::MASKED_ID));

        $this->assertRefused(self::NOT_VERIFIED, $this->place($quoteId));
        $this->assertNotRecorded($quoteId);
    }

    /**
     * A proof on a cart with no masked id refuses the placement.
     *
     * @magentoAppArea webapi_rest
     * @return void
     */
    public function testAProofOnACartWithoutAMaskedIdIsRefused(): void
    {
        $this->configureSecret(self::SECRET);
        $quoteId = CartRows::insertOpenGuestCart(null);
        $this->sendProof($this->sign(self::SECRET, (string)$quoteId));

        $this->assertRefused(self::NOT_VERIFIED, $this->place($quoteId));
        $this->assertNotRecorded($quoteId);
    }

    /**
     * Outside REST and GraphQL no proof is read and nothing is recognised.
     *
     * @magentoAppArea global
     * @return void
     */
    public function testOutsideRestAndGraphQlNothingIsRecognised(): void
    {
        $this->configureSecret(self::SECRET);
        $quoteId = CartRows::insertOpenGuestCart(self::MASKED_ID);
        $this->sendProof($this->sign(self::SECRET, self::MASKED_ID));

        $this->assertPlacementWentOn($this->place($quoteId));
        $this->assertNotRecorded($quoteId);
    }

    /**
     * A valid proof for the cart of a real guest order, whose masked id outlives the placement, is refused: the
     * customer's order must never become a test order.
     *
     * @magentoAppArea webapi_rest
     * @return void
     */
    public function testAProofCannotAdoptTheClosedCartOfAnOrdinaryOrder(): void
    {
        $this->configureSecret(self::SECRET);
        $quoteId = CartRows::insertClosedGuestCart(self::MASKED_ID);
        OrderRows::insert($quoteId);
        $this->sendProof($this->sign(self::SECRET, self::MASKED_ID));

        $this->assertRefused(self::NOT_OPEN, $this->place($quoteId));
        $this->assertNotRecorded($quoteId);
    }

    /**
     * A closed cart is refused even when it never became an order.
     *
     * @magentoAppArea webapi_rest
     * @return void
     */
    public function testAProofOnAClosedCartWithoutAnOrderIsRefused(): void
    {
        $this->configureSecret(self::SECRET);
        $quoteId = CartRows::insertClosedGuestCart(self::MASKED_ID);
        $this->sendProof($this->sign(self::SECRET, self::MASKED_ID));

        $this->assertRefused(self::NOT_OPEN, $this->place($quoteId));
        $this->assertNotRecorded($quoteId);
    }

    /**
     * An open cart that already has an ordinary order is refused: the order it has would turn into a test order.
     *
     * @magentoAppArea graphql
     * @return void
     */
    public function testAProofOnAnOpenCartWithAnOrdinaryOrderIsRefused(): void
    {
        $this->configureSecret(self::SECRET);
        $quoteId = CartRows::insertOpenGuestCart(self::MASKED_ID);
        OrderRows::insert($quoteId);
        $this->sendProof($this->sign(self::SECRET, self::MASKED_ID));

        $this->assertRefused(self::NOT_OPEN, $this->place($quoteId));
        $this->assertNotRecorded($quoteId);
    }

    /**
     * A test cart that was closed is refused too, and the refusal leaves its record alone: its order stays a test order.
     *
     * @magentoAppArea webapi_rest
     * @return void
     */
    public function testAProofOnAClosedTestCartIsRefusedAndKeepsTheRecord(): void
    {
        $this->configureSecret(self::SECRET);
        $quoteId = CartRows::insertClosedGuestCart(self::MASKED_ID);
        $this->records()->recognise($quoteId);
        OrderRows::insert($quoteId);
        $this->sendProof($this->sign(self::SECRET, self::MASKED_ID));

        $this->assertRefused(self::NOT_OPEN, $this->place($quoteId));
        self::assertSame($quoteId, $this->repository()->getByQuoteId($quoteId)->getQuoteId());
    }

    /**
     * A signed retry that finds the cart locked keeps the record of the placement holding the lock, whose order is not
     * saved yet, so that order stays a test order.
     *
     * @magentoAppArea webapi_rest
     * @return void
     */
    public function testARetryThatLosesTheCartsLockKeepsTheRecord(): void
    {
        $this->configureSecret(self::SECRET);
        $quoteId = CartRows::insertOpenGuestCart(self::MASKED_ID);
        $this->records()->recognise($quoteId);
        $this->sendProof($this->sign(self::SECRET, self::MASKED_ID));

        self::assertInstanceOf(CartLockedException::class, $this->place($quoteId, $this->lockedCartManagement()));
        self::assertSame($quoteId, $this->repository()->getByQuoteId($quoteId)->getQuoteId());
    }

    /**
     * A proof sent while a customer's ordinary placement holds the cart's lock passes every check, because that order
     * is not saved yet. It loses the lock and must leave no record behind: the customer's order stays an ordinary one.
     *
     * @magentoAppArea webapi_rest
     * @return void
     */
    public function testAPlacementThatLosesTheLockOnACartWithNoRecordLeavesNoRecord(): void
    {
        $this->configureSecret(self::SECRET);
        $quoteId = CartRows::insertOpenGuestCart(self::MASKED_ID);
        $this->sendProof($this->sign(self::SECRET, self::MASKED_ID));

        self::assertInstanceOf(CartLockedException::class, $this->place($quoteId, $this->lockedCartManagement()));
        $this->assertNotRecorded($quoteId);
    }

    /**
     * A signed retry that fails on anything further in keeps a record it found: the record is another placement's,
     * perhaps one still running, whose order is not saved yet.
     *
     * @magentoAppArea webapi_rest
     * @return void
     */
    public function testARetryThatFailsFurtherInKeepsTheRecordItFound(): void
    {
        $this->configureSecret(self::SECRET);
        $quoteId = CartRows::insertOpenGuestCart(self::MASKED_ID);
        $this->records()->recognise($quoteId);
        $this->sendProof($this->sign(self::SECRET, self::MASKED_ID));

        $this->assertPlacementWentOn($this->place($quoteId));
        self::assertSame($quoteId, $this->repository()->getByQuoteId($quoteId)->getQuoteId());
    }

    /**
     * A placement that made the record and fails after saving an order keeps the record: that order exists, and it is a
     * test order.
     *
     * @magentoAppArea webapi_rest
     * @return void
     */
    public function testAPlacementThatMadeTheRecordAndFailsAfterSavingAnOrderKeepsIt(): void
    {
        $this->configureSecret(self::SECRET);
        $quoteId = CartRows::insertOpenGuestCart(self::MASKED_ID);
        $this->sendProof($this->sign(self::SECRET, self::MASKED_ID));
        $plugin = Bootstrap::getObjectManager()->get(RecogniseTestCart::class);
        self::assertInstanceOf(RecogniseTestCart::class, $plugin);

        try {
            $plugin->aroundPlaceOrder(
                $this->cartManagement(),
                static function () use ($quoteId): never {
                    OrderRows::insert($quoteId);

                    throw new \RuntimeException('The payment provider did not answer.');
                },
                $quoteId
            );
            self::fail('A failed placement was reported as placed.');
        } catch (\RuntimeException $failure) {
            self::assertSame('The payment provider did not answer.', $failure->getMessage());
        }

        self::assertSame($quoteId, $this->repository()->getByQuoteId($quoteId)->getQuoteId());
    }

    /**
     * A real, finished guest placement with a proof is a linked test order, and placing its closed cart again is
     * refused without touching the record.
     *
     * @magentoDataFixture Magento/Sales/_files/guest_quote_with_addresses.php
     * @magentoAppArea webapi_rest
     * @return void
     */
    public function testAFinishedPlacementIsATestOrderAndItsCartCannotBePlacedAgain(): void
    {
        $this->configureSecret(self::SECRET);
        [$quoteId, $maskedId] = $this->fixtureCart();
        $this->sendProof($this->sign(self::SECRET, $maskedId));

        $orderId = (int)$this->cartManagement()->placeOrder($quoteId);

        self::assertGreaterThan(0, $orderId);
        self::assertSame($orderId, $this->repository()->getByQuoteId($quoteId)->getOrderId());
        self::assertTrue($this->testOrders()->isTestOrderId($orderId));
        $this->assertRefused(self::NOT_OPEN, $this->place($quoteId));
        self::assertSame($orderId, $this->repository()->getByQuoteId($quoteId)->getOrderId());
    }

    /**
     * A real guest order placed without a proof stays ordinary when a valid proof is sent for its cart afterwards.
     *
     * @magentoDataFixture Magento/Sales/_files/guest_quote_with_addresses.php
     * @magentoAppArea webapi_rest
     * @return void
     */
    public function testAFinishedOrdinaryOrderCannotBeAdopted(): void
    {
        $this->configureSecret(self::SECRET);
        [$quoteId, $maskedId] = $this->fixtureCart();
        $orderId = (int)$this->cartManagement()->placeOrder($quoteId);
        $this->sendProof($this->sign(self::SECRET, $maskedId));

        $this->assertRefused(self::NOT_OPEN, $this->place($quoteId));
        $this->assertNotRecorded($quoteId);
        self::assertFalse($this->testOrders()->isTestOrderId($orderId));
    }

    /**
     * A customer the deployment names places a real order through the core with a proof: it is a linked test order, and
     * the runner's route ends it by the cart's masked id, as it ends a guest's.
     *
     * Runs in the GraphQL area because REST also refuses to save a customer's cart for a caller who is not that customer,
     * and this test signs nobody in.
     *
     * @magentoDataFixture Magento/Checkout/_files/customer_quote_ready_for_order.php
     * @magentoAppArea graphql
     * @return void
     */
    public function testANamedCustomersPlacementIsATestOrderTheRunnerCanEnd(): void
    {
        $this->configureSecret(self::SECRET, ['  ' . strtoupper(self::FIXTURE_CUSTOMER) . ' ']);
        $quoteId = $this->customerCart(Area::AREA_GRAPHQL);
        $this->sendProof($this->sign(self::SECRET, self::MASKED_ID));

        $orderId = (int)$this->cartManagement()->placeOrder($quoteId);

        self::assertGreaterThan(0, $orderId);
        self::assertSame($orderId, $this->repository()->getByQuoteId($quoteId)->getOrderId());
        self::assertTrue($this->testOrders()->isTestOrderId($orderId));

        $this->sendProof($this->sign(self::SECRET, self::MASKED_ID, TestOrderAction::Abandon));
        $abandonment = Bootstrap::getObjectManager()->get(TestOrderAbandonmentInterface::class);
        self::assertInstanceOf(TestOrderAbandonmentInterface::class, $abandonment);

        self::assertSame(Order::STATE_CANCELED, $abandonment->abandon(self::MASKED_ID)->getState());
    }

    /**
     * A customer the deployment does not name is refused with a valid proof: nothing is recorded and no order exists.
     *
     * @magentoDataFixture Magento/Checkout/_files/customer_quote_ready_for_order.php
     * @magentoAppArea webapi_rest
     * @return void
     */
    public function testAnUnnamedCustomersPlacementIsRefused(): void
    {
        $this->configureSecret(self::SECRET, ['buyer@example.test']);
        $quoteId = $this->customerCart();
        $this->sendProof($this->sign(self::SECRET, self::MASKED_ID));

        $stopped = $this->place($quoteId);

        self::assertInstanceOf(LocalizedException::class, $stopped);
        $this->assertRefused(self::WRONG_OWNER, $stopped);
        $this->assertNotRecorded($quoteId);
        self::assertSame([], $this->cartOrders()->ofCart($quoteId));
    }

    /**
     * With no list in the deployment configuration a customer's cart is refused as any unnamed customer's.
     *
     * @magentoDataFixture Magento/Checkout/_files/customer_quote_ready_for_order.php
     * @magentoAppArea webapi_rest
     * @return void
     */
    public function testACustomersPlacementIsRefusedWhileTheDeploymentNamesNobody(): void
    {
        $this->configureSecret(self::SECRET);
        $quoteId = $this->customerCart();
        $this->sendProof($this->sign(self::SECRET, self::MASKED_ID));

        $this->assertRefused(self::WRONG_OWNER, $this->place($quoteId));
        $this->assertNotRecorded($quoteId);
        self::assertSame([], $this->cartOrders()->ofCart($quoteId));
    }

    /**
     * The address a checkout wrote on the cart does not name the account behind it, even when it is a named address.
     *
     * @magentoDataFixture Magento/Checkout/_files/customer_quote_ready_for_order.php
     * @magentoAppArea webapi_rest
     * @return void
     */
    public function testTheCartsOwnEmailDoesNotMakeACustomerATestCustomer(): void
    {
        $this->configureSecret(self::SECRET, ['buyer@example.test']);
        $quoteId = $this->customerCart();
        $resource = Bootstrap::getObjectManager()->get(ResourceConnection::class);
        self::assertInstanceOf(ResourceConnection::class, $resource);
        $resource->getConnection()->update(
            $resource->getTableName('quote'),
            ['customer_email' => 'buyer@example.test'],
            ['entity_id = ?' => $quoteId]
        );
        $this->sendProof($this->sign(self::SECRET, self::MASKED_ID));

        $this->assertRefused(self::WRONG_OWNER, $this->place($quoteId));
        $this->assertNotRecorded($quoteId);
    }

    /**
     * Place the cart's order through the container's service, and hand back what stopped it.
     *
     * @param int $quoteId Cart (quote) entity id
     * @param CartManagementInterface|null $cartManagement The service placing it; the container's shared one when null
     * @return \Throwable
     */
    private function place(int $quoteId, ?CartManagementInterface $cartManagement = null): \Throwable
    {
        try {
            ($cartManagement ?? $this->cartManagement())->placeOrder($quoteId);
        } catch (\Throwable $stopped) {
            return $stopped;
        }

        self::fail('A cart the core cannot finish was placed.');
    }

    /**
     * Assert the placement was refused by recognition, not stopped by the core.
     *
     * @param string $message Refusal expected
     * @param \Throwable $stopped What stopped the placement
     * @return void
     */
    private function assertRefused(string $message, \Throwable $stopped): void
    {
        self::assertSame($message, $stopped->getMessage());
    }

    /**
     * Assert the placement passed every refusal of recognition and was stopped by the core.
     *
     * @param \Throwable $stopped What stopped the placement
     * @return void
     */
    private function assertPlacementWentOn(\Throwable $stopped): void
    {
        self::assertNotContains(
            $stopped->getMessage(),
            [self::WRONG_OWNER, self::NOT_VERIFIED, self::NOT_OPEN],
            'The placement was refused.'
        );
        self::assertSame(self::THE_CORE_STOPPED, $stopped->getMessage());
    }

    /**
     * An open guest cart recognised long ago, with an order, as when a payment provider saved it before placement
     * failed.
     *
     * @return int Cart (quote) entity id
     */
    private function testCartWithAnOrder(): int
    {
        $quoteId = CartRows::insertOpenGuestCart(self::MASKED_ID);
        $this->records()->recognise($quoteId);
        OrderRows::insert($quoteId);
        $resource = Bootstrap::getObjectManager()->get(ResourceConnection::class);
        self::assertInstanceOf(ResourceConnection::class, $resource);
        $resource->getConnection()->update(
            $resource->getTableName(TestOrderRepository::TABLE_NAME),
            [TestOrderInterface::RECOGNISED_AT => self::LONG_AGO],
            [TestOrderInterface::QUOTE_ID . ' = ?' => $quoteId]
        );

        return $quoteId;
    }

    /**
     * The guest cart of the data fixture, ready to be placed in the area under test.
     *
     * @return array{int, string} The cart's entity id and its masked id
     */
    private function fixtureCart(): array
    {
        $quoteId = $this->fixtureCartId('guest_quote');
        $maskedIds = Bootstrap::getObjectManager()->get(QuoteIdToMaskedQuoteIdInterface::class);
        self::assertInstanceOf(QuoteIdToMaskedQuoteIdInterface::class, $maskedIds);

        return [$quoteId, $maskedIds->execute($quoteId)];
    }

    /**
     * The customer's cart of the data fixture, given a masked id, ready to be placed in the area under test.
     *
     * @param string $area The area under test
     * @return int Cart (quote) entity id
     */
    private function customerCart(string $area = Area::AREA_WEBAPI_REST): int
    {
        $quoteId = $this->fixtureCartId(self::FIXTURE_CUSTOMER_ORDER, $area);
        $resource = Bootstrap::getObjectManager()->get(ResourceConnection::class);
        self::assertInstanceOf(ResourceConnection::class, $resource);
        $resource->getConnection()->insert(
            $resource->getTableName('quote_id_mask'),
            ['quote_id' => $quoteId, 'masked_id' => self::MASKED_ID]
        );

        return $quoteId;
    }

    /**
     * The id of a data fixture's cart, with the area under test loaded again: a fixture may load another.
     *
     * @param string $reservedOrderId The order number the fixture reserved for the cart
     * @param string $area The area under test
     * @return int Cart (quote) entity id
     */
    private function fixtureCartId(string $reservedOrderId, string $area = Area::AREA_WEBAPI_REST): int
    {
        Bootstrap::getInstance()->getBootstrap()->getApplication()->loadArea($area);
        $resource = Bootstrap::getObjectManager()->get(ResourceConnection::class);
        self::assertInstanceOf(ResourceConnection::class, $resource);
        $connection = $resource->getConnection();
        $quoteId = $connection->fetchOne(
            $connection->select()
                ->from($resource->getTableName('quote'), ['entity_id'])
                ->where('reserved_order_id = ?', $reservedOrderId)
        );
        self::assertIsNumeric($quoteId);

        return (int)$quoteId;
    }

    /**
     * Assert the cart was not recorded as a test cart.
     *
     * @param int $quoteId Cart (quote) entity id
     * @return void
     */
    private function assertNotRecorded(int $quoteId): void
    {
        try {
            $this->repository()->getByQuoteId($quoteId);
        } catch (NoSuchEntityException) {
            return;
        }

        self::fail('The cart was recorded as a test cart.');
    }

    /**
     * Configure the shop's secret and test customers in the deployment configuration, or remove them.
     *
     * @param string|null $secret Secret; null for none, and no test customers either
     * @param list<string> $customers The test customers' addresses
     * @return void
     */
    private function configureSecret(?string $secret, array $customers = []): void
    {
        $configuration = ['proof_secret' => $secret, 'customers' => $customers];
        putenv(
            $secret === null
                ? self::OVERRIDE_VARIABLE
                : self::OVERRIDE_VARIABLE . '=' . json_encode(['test_order' => $configuration])
        );
        $this->deploymentConfig()->resetData();
    }

    /**
     * Send the proof header with the request being served, or stop sending it.
     *
     * @param string|null $proof Proof; null to send none
     * @return void
     */
    private function sendProof(?string $proof): void
    {
        $request = Bootstrap::getObjectManager()->get(RequestInterface::class);
        self::assertInstanceOf(HttpRequest::class, $request);
        $headers = $request->getHeaders();
        self::assertInstanceOf(Headers::class, $headers);
        $sent = $headers->get(RequestProofSource::HEADER);
        if ($sent instanceof HeaderInterface) {
            $headers->removeHeader($sent);
        }

        if ($proof !== null) {
            $headers->addHeaderLine(RequestProofSource::HEADER, $proof);
        }
    }

    /**
     * Let the container's guard pool hold one guard that refuses every store, and rebuild recognition around it.
     *
     * @param string $reason The guard's reason
     * @return void
     */
    private function guardRefusing(string $reason): void
    {
        $guard = new class ($reason) implements RecognitionGuardInterface {
            /**
             * @param string $reason The reason given for every store
             */
            public function __construct(private readonly string $reason)
            {
            }

            /**
             * @inheritDoc
             */
            public function refusalFor(int $storeId): Phrase
            {
                return new Phrase($this->reason);
            }
        };
        $objectManager = $this->objectManager();
        $objectManager->addSharedInstance(
            new RecognitionGuardPool(['refusing' => $guard]),
            RecognitionGuardPool::class
        );
        $objectManager->removeSharedInstance(TestCartRecognition::class);
    }

    /**
     * Let the container's cart guard pool hold one guard that refuses one cart, and rebuild recognition around it.
     *
     * @param int $quoteId Cart (quote) entity id it refuses; it has no objection to any other
     * @param string $reason The guard's reason
     * @return void
     */
    private function cartGuardRefusing(int $quoteId, string $reason): void
    {
        $guard = new class ($quoteId, $reason) implements CartGuardInterface {
            /**
             * @param int $quoteId The cart it refuses
             * @param string $reason The reason given for it
             */
            public function __construct(private readonly int $quoteId, private readonly string $reason)
            {
            }

            /**
             * @inheritDoc
             */
            public function refusalForCart(CartInterface $cart, ?string $paymentMethod): ?Phrase
            {
                return (int)$cart->getId() === $this->quoteId ? new Phrase($this->reason) : null;
            }
        };
        $objectManager = $this->objectManager();
        $objectManager->addSharedInstance(new CartGuardPool(['refusing' => $guard]), CartGuardPool::class);
        $objectManager->removeSharedInstance(TestCartRecognition::class);
    }

    /**
     * Let the container's cart guard pool hold one guard that refuses one payment method, and rebuild recognition.
     *
     * @param string $method The method it refuses; it has no objection to any other
     * @param string $reason The guard's reason
     * @return void
     */
    private function cartGuardRefusingMethod(string $method, string $reason): void
    {
        $guard = new class ($method, $reason) implements CartGuardInterface {
            /**
             * @param string $method The method it refuses
             * @param string $reason The reason given for it
             */
            public function __construct(private readonly string $method, private readonly string $reason)
            {
            }

            /**
             * @inheritDoc
             */
            public function refusalForCart(CartInterface $cart, ?string $paymentMethod): ?Phrase
            {
                return $paymentMethod === $this->method ? new Phrase($this->reason) : null;
            }
        };
        $objectManager = $this->objectManager();
        $objectManager->addSharedInstance(new CartGuardPool(['refusing' => $guard]), CartGuardPool::class);
        $objectManager->removeSharedInstance(TestCartRecognition::class);
    }

    /**
     * A fresh proof as the end-to-end runner makes it: an HMAC over `<action>|<subject>|<unix-seconds>`.
     *
     * @param string $secret Secret signed with
     * @param string $subject Subject signed over
     * @param TestOrderAction $action Action signed for
     * @return string
     */
    private function sign(string $secret, string $subject, TestOrderAction $action = TestOrderAction::Place): string
    {
        $now = time();

        return $now . '.' . hash_hmac('sha256', $action->value . '|' . $subject . '|' . $now, $secret);
    }

    /**
     * The order-placement service of the area under test, finding every cart's lock held by another placement.
     *
     * @return CartManagementInterface
     */
    private function lockedCartManagement(): CartManagementInterface
    {
        $locked = $this->createStub(CartMutexInterface::class);
        $locked->method('execute')
            ->willThrowException(new CartLockedException(__('The cart is locked for processing.')));
        $cartManagement = Bootstrap::getObjectManager()->create(
            CartManagementInterface::class,
            ['cartMutex' => $locked]
        );
        self::assertInstanceOf(CartManagementInterface::class, $cartManagement);

        return $cartManagement;
    }

    /**
     * The order-placement service of the area under test.
     *
     * @return CartManagementInterface
     */
    private function cartManagement(): CartManagementInterface
    {
        $cartManagement = Bootstrap::getObjectManager()->get(CartManagementInterface::class);
        self::assertInstanceOf(CartManagementInterface::class, $cartManagement);

        return $cartManagement;
    }

    /**
     * The question an integration asks.
     *
     * @return TestOrdersInterface
     */
    private function testOrders(): TestOrdersInterface
    {
        $testOrders = Bootstrap::getObjectManager()->get(TestOrdersInterface::class);
        self::assertInstanceOf(TestOrdersInterface::class, $testOrders);

        return $testOrders;
    }

    /**
     * The orders placed from a cart.
     *
     * @return CartOrdersInterface
     */
    private function cartOrders(): CartOrdersInterface
    {
        $cartOrders = Bootstrap::getObjectManager()->get(CartOrdersInterface::class);
        self::assertInstanceOf(CartOrdersInterface::class, $cartOrders);

        return $cartOrders;
    }

    /**
     * The test framework's object manager.
     *
     * @return ObjectManager
     */
    private function objectManager(): ObjectManager
    {
        $objectManager = Bootstrap::getObjectManager();
        self::assertInstanceOf(ObjectManager::class, $objectManager);

        return $objectManager;
    }

    /**
     * @return DeploymentConfig
     */
    private function deploymentConfig(): DeploymentConfig
    {
        $deploymentConfig = Bootstrap::getObjectManager()->get(DeploymentConfig::class);
        self::assertInstanceOf(DeploymentConfig::class, $deploymentConfig);

        return $deploymentConfig;
    }

    /**
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
}
