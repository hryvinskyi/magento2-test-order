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
use Hryvinskyi\TestOrder\Api\Data\TestOrderInterface;
use Hryvinskyi\TestOrder\Api\RecognitionGuardInterface;
use Hryvinskyi\TestOrder\Api\TestCartRecordsInterface;
use Hryvinskyi\TestOrder\Api\TestOrderRepositoryInterface;
use Hryvinskyi\TestOrder\Api\TestOrdersInterface;
use Hryvinskyi\TestOrder\Domain\TestOrderAction;
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
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Phrase;
use Magento\Quote\Api\CartManagementInterface;
use Magento\Quote\Model\CartLockedException;
use Magento\Quote\Model\CartMutexInterface;
use Magento\Quote\Model\QuoteIdToMaskedQuoteIdInterface;
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

    private const string THE_CORE_STOPPED
        = 'A server error stopped your order from being placed. Please try to place your order again.';

    private const string GUEST_ONLY = 'A test order can only be placed from a guest cart, so the order was not placed.';

    private const string NOT_VERIFIED
        = 'The test-order proof sent with this order could not be verified, so the order was not placed.';

    private const string NOT_OPEN
        = 'A test order can only be placed from an open cart that has no ordinary order, so the order was not placed.';

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
     * A proof on a cart guests do not know by a masked id refuses the placement.
     *
     * @magentoAppArea webapi_rest
     * @return void
     */
    public function testAProofOnACartWithoutAMaskedIdIsRefused(): void
    {
        $this->configureSecret(self::SECRET);
        $quoteId = CartRows::insertOpenGuestCart(null);
        $this->sendProof($this->sign(self::SECRET, (string)$quoteId));

        $this->assertRefused(self::GUEST_ONLY, $this->place($quoteId));
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
            [self::GUEST_ONLY, self::NOT_VERIFIED, self::NOT_OPEN],
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
     * The fixture loads the storefront area, so the area of the test is loaded again.
     *
     * @return array{int, string} The cart's entity id and its masked id
     */
    private function fixtureCart(): array
    {
        Bootstrap::getInstance()->getBootstrap()->getApplication()->loadArea(Area::AREA_WEBAPI_REST);
        $resource = Bootstrap::getObjectManager()->get(ResourceConnection::class);
        self::assertInstanceOf(ResourceConnection::class, $resource);
        $connection = $resource->getConnection();
        $quoteId = $connection->fetchOne(
            $connection->select()
                ->from($resource->getTableName('quote'), ['entity_id'])
                ->where('reserved_order_id = ?', 'guest_quote')
        );
        self::assertIsNumeric($quoteId);
        $quoteId = (int)$quoteId;
        $maskedIds = Bootstrap::getObjectManager()->get(QuoteIdToMaskedQuoteIdInterface::class);
        self::assertInstanceOf(QuoteIdToMaskedQuoteIdInterface::class, $maskedIds);

        return [$quoteId, $maskedIds->execute($quoteId)];
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
     * Configure the shop's secret in the deployment configuration, or remove it.
     *
     * @param string|null $secret Secret; null for none
     * @return void
     */
    private function configureSecret(?string $secret): void
    {
        putenv(
            $secret === null
                ? self::OVERRIDE_VARIABLE
                : self::OVERRIDE_VARIABLE . '=' . json_encode(['test_order' => ['proof_secret' => $secret]])
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
