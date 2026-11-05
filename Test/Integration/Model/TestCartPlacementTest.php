<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\TestOrder\Test\Integration\Model;

use Hryvinskyi\TestOrder\Api\Data\TestOrderInterface;
use Hryvinskyi\TestOrder\Api\TestCartPlacementInterface;
use Hryvinskyi\TestOrder\Api\TestOrderRepositoryInterface;
use Hryvinskyi\TestOrder\Api\TestOrdersInterface;
use Hryvinskyi\TestOrder\Domain\TestOrderAction;
use Hryvinskyi\TestOrder\Model\ProofSource\RequestProofSource;
use Hryvinskyi\TestOrder\Test\Integration\CartRows;
use Laminas\Http\Header\HeaderInterface;
use Laminas\Http\Headers;
use Magento\Framework\App\Area;
use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Quote\Api\CartManagementInterface;
use Magento\Quote\Model\QuoteIdToMaskedQuoteIdInterface;
use Magento\TestFramework\Helper\Bootstrap;
use PHPUnit\Framework\TestCase;

/**
 * The placement port as a checkout that places some other way reaches it: from the container, addressing the cart by
 * its masked id, with the real header, secret and record.
 *
 * @magentoDbIsolation enabled
 * @magentoAppIsolation enabled
 */
class TestCartPlacementTest extends TestCase
{
    private const string SECRET = 'an-integration-test-order-secret-0123456789';

    private const string MASKED_ID = 'TestCartPlacement000000000000aaa';

    private const string OVERRIDE_VARIABLE = 'MAGENTO_DC__OVERRIDE';

    private const string THE_CORE_STOPPED
        = 'A server error stopped your order from being placed. Please try to place your order again.';

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
        putenv(self::OVERRIDE_VARIABLE . '=' . json_encode(['test_order' => ['proof_secret' => self::SECRET]]));
        $this->deploymentConfig()->resetData();
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
     * A placement answered in the request with the proof places nothing yet, and the record stays. The order placed
     * later from that cart, in another area and with no proof, is a test order, linked to the record.
     *
     * @magentoDataFixture Magento/Sales/_files/guest_quote_with_addresses.php
     * @magentoAppArea webapi_rest
     * @return void
     */
    public function testACartRecordedNowGetsALinkedTestOrderWhenItsOrderIsPlacedLaterWithNoProof(): void
    {
        Bootstrap::getInstance()->getBootstrap()->getApplication()->loadArea(Area::AREA_WEBAPI_REST);
        [$quoteId, $maskedId] = $this->fixtureCart();
        $this->sendProof($this->sign($maskedId));

        $answer = $this->placement()->place($maskedId, static fn (): string => 'redirect to pay');

        self::assertSame('redirect to pay', $answer);
        self::assertNull($this->record($quoteId)->getOrderId());

        $this->sendProof(null);
        Bootstrap::getInstance()->getBootstrap()->getApplication()->loadArea(Area::AREA_FRONTEND);
        $orderId = (int)$this->cartManagement()->placeOrder($quoteId);

        self::assertGreaterThan(0, $orderId);
        self::assertSame($orderId, $this->record($quoteId)->getOrderId());
        self::assertTrue($this->testOrders()->isTestOrderId($orderId));
    }

    /**
     * A checkout's placement wrapped around the core's, which goes through the module's own plugin: the record is the
     * outer call's, so the failure further in forgets it, and the core's failure comes out unchanged.
     *
     * @magentoAppArea webapi_rest
     * @return void
     */
    public function testAWrappedPlacementThatFailsInsideThePluginLeavesNoRecord(): void
    {
        $quoteId = CartRows::insertOpenGuestCart(self::MASKED_ID);
        $this->sendProof($this->sign(self::MASKED_ID));
        $cartManagement = $this->cartManagement();

        try {
            $this->placement()->place(
                self::MASKED_ID,
                static fn (): int => $cartManagement->placeOrder($quoteId)
            );
            self::fail('A cart the core cannot finish was placed.');
        } catch (LocalizedException $stopped) {
            self::assertSame(self::THE_CORE_STOPPED, $stopped->getMessage());
        }

        $this->assertNotRecorded($quoteId);
    }

    /**
     * A proof on a masked id no cart has is refused with the lookup's own exception, and the placement never runs.
     *
     * @magentoAppArea webapi_rest
     * @return void
     */
    public function testAProofOnAMaskedIdOfNoCartIsRefused(): void
    {
        $this->sendProof($this->sign(self::MASKED_ID));

        $this->expectException(NoSuchEntityException::class);

        $this->placement()->place(self::MASKED_ID, static function (): never {
            self::fail('A placement of no cart ran.');
        });
    }

    /**
     * The guest cart of the data fixture.
     *
     * @return array{int, string} The cart's entity id and its masked id
     */
    private function fixtureCart(): array
    {
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
            $this->record($quoteId);
        } catch (NoSuchEntityException) {
            return;
        }

        self::fail('The cart was recorded as a test cart.');
    }

    /**
     * The cart's record.
     *
     * @param int $quoteId Cart (quote) entity id
     * @return TestOrderInterface
     * @throws NoSuchEntityException When the cart has none
     */
    private function record(int $quoteId): TestOrderInterface
    {
        $repository = Bootstrap::getObjectManager()->get(TestOrderRepositoryInterface::class);
        self::assertInstanceOf(TestOrderRepositoryInterface::class, $repository);

        return $repository->getByQuoteId($quoteId);
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
     * A fresh placement proof as the end-to-end runner makes it: an HMAC over `<action>|<subject>|<unix-seconds>`.
     *
     * @param string $subject Subject signed over
     * @return string
     */
    private function sign(string $subject): string
    {
        $now = time();

        return $now . '.' . hash_hmac(
            'sha256',
            TestOrderAction::Place->value . '|' . $subject . '|' . $now,
            self::SECRET
        );
    }

    /**
     * The port, as the container resolves it.
     *
     * @return TestCartPlacementInterface
     */
    private function placement(): TestCartPlacementInterface
    {
        $placement = Bootstrap::getObjectManager()->get(TestCartPlacementInterface::class);
        self::assertInstanceOf(TestCartPlacementInterface::class, $placement);

        return $placement;
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
     * @return DeploymentConfig
     */
    private function deploymentConfig(): DeploymentConfig
    {
        $deploymentConfig = Bootstrap::getObjectManager()->get(DeploymentConfig::class);
        self::assertInstanceOf(DeploymentConfig::class, $deploymentConfig);

        return $deploymentConfig;
    }
}
