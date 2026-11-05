<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\TestOrder\Test\Integration\Model;

use Hryvinskyi\TestOrder\Api\AbandonTestOrderInterface;
use Hryvinskyi\TestOrder\Api\Data\TestOrderInterface;
use Hryvinskyi\TestOrder\Api\PaidTestOrderRefundInterface;
use Hryvinskyi\TestOrder\Api\StaleTestCartsInterface;
use Hryvinskyi\TestOrder\Api\TestCartRecordsInterface;
use Hryvinskyi\TestOrder\Api\TestOrderRepositoryInterface;
use Hryvinskyi\TestOrder\Api\TestOrdersInterface;
use Hryvinskyi\TestOrder\Cron\AbandonStaleTestOrders;
use Hryvinskyi\TestOrder\Model\AbandonTestOrder;
use Hryvinskyi\TestOrder\Model\OfflinePaidTestOrderRefund;
use Hryvinskyi\TestOrder\Model\TestOrderRepository;
use Magento\Framework\App\Area;
use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Exception\LocalizedException;
use Magento\InventoryApi\Api\GetSourceItemsBySkuInterface;
use Magento\InventoryReservations\Model\ResourceModel\GetReservationsQuantityCache;
use Magento\InventorySalesApi\Api\GetProductSalableQtyInterface;
use Magento\Payment\Helper\Data as PaymentData;
use Magento\Payment\Model\MethodInterface;
use Magento\Quote\Api\CartManagementInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\InvoiceOrderInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\ResourceModel\Order as OrderResource;
use Magento\TestFramework\Helper\Bootstrap;
use Magento\TestFramework\Mail\Template\TransportBuilderMock;
use Magento\TestFramework\ObjectManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * A real order placed through the core from a recognised cart, invoiced as a provider's payment would leave it, and
 * ended through the container's own wiring: the real credit memo, the real stock and the real clean-up selection.
 *
 * @magentoDbIsolation enabled
 * @magentoAppIsolation enabled
 */
class AbandonPaidTestOrderTest extends TestCase
{
    private const string OVERRIDE_VARIABLE = 'MAGENTO_DC__OVERRIDE';

    private const string FIXTURE_ORDER = 'guest_quote';

    private const string FIXTURE_SKU = 'simple-product-guest-quote';

    private const int DEFAULT_STOCK = 1;

    private const string LONG_AGO = '2026-01-01 00:00:00';

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
        $this->service(DeploymentConfig::class)->resetData();
        $this->objectManager()->removeSharedInstance(PaymentData::class);
    }

    /**
     * The container refunds paid test orders offline.
     *
     * @return void
     */
    public function testTheContainerHandsOutTheOfflineRefund(): void
    {
        self::assertInstanceOf(OfflinePaidTestOrderRefund::class, $this->service(PaidTestOrderRefundInterface::class));
    }

    /**
     * Abandoned from either place that ends a test order, a paid order closes with one credit memo for the whole
     * amount, nothing asks the payment method to refund, no e-mail goes out, and what the order held of the stock is
     * salable again, with none of it written off at the sources. It is kept although cancelled orders are being deleted.
     *
     * @dataProvider areasThatEndATestOrder
     * @magentoDataFixture Magento/Sales/_files/guest_quote_with_addresses.php
     * @magentoConfigFixture default/test_order/cleanup/delete_cancelled 1
     * @magentoAppArea frontend
     * @param string $area The area the order is ended in
     * @return void
     */
    public function testAPaidTestOrderClosesWithOneCreditMemoAndItsStockBack(string $area): void
    {
        $salableBefore = $this->salableQuantity();
        $atSourcesBefore = $this->quantityAtSources();
        self::assertGreaterThan(0.0, $atSourcesBefore);
        [$quoteId, $orderId] = $this->paidTestOrder();
        self::assertSame($salableBefore - 1.0, $this->salableQuantity());
        $order = $this->service(OrderRepositoryInterface::class)->get($orderId);
        self::assertSame(Order::STATE_PROCESSING, $order->getState());
        self::assertGreaterThan(0.0, (float)$order->getTotalPaid());
        $this->giveAPaymentMethodThatMustNotRefund();
        $mail = $this->service(TransportBuilderMock::class);
        $lastMail = $mail->getSentMessage();
        $this->loadArea($area);

        $result = $this->abandonTestOrder()->abandon($quoteId);

        $order = $this->service(OrderRepositoryInterface::class)->get($orderId);
        self::assertSame(Order::STATE_CLOSED, $result->getState());
        self::assertSame((string)$order->getIncrementId(), $result->getIncrementId());
        self::assertFalse($result->isDeleted());
        self::assertSame(Order::STATE_CLOSED, $order->getState());
        self::assertSame(1, $this->creditmemoCount($orderId));
        self::assertEqualsWithDelta((float)$order->getTotalPaid(), (float)$order->getTotalOfflineRefunded(), 0.0001);
        self::assertEqualsWithDelta(0.0, (float)$order->getTotalOnlineRefunded(), 0.0001);
        self::assertSame($lastMail, $mail->getSentMessage());
        self::assertSame($salableBefore, $this->salableQuantity());
        self::assertSame($atSourcesBefore, $this->quantityAtSources());
    }

    /**
     * The two places that end a test order, each in its own area.
     *
     * @return array<string, array{string}>
     */
    public static function areasThatEndATestOrder(): array
    {
        return [
            'the runner, over REST' => [Area::AREA_WEBAPI_REST],
            'the clean-up job' => [Area::AREA_CRONTAB],
        ];
    }

    /**
     * Two requests that both read the paid order before either refunded it, as a late abandon and the teardown's second
     * one do: the first refunds, the second finds it closed and answers it so, one credit memo exists and the stock came
     * back once.
     *
     * @magentoDataFixture Magento/Sales/_files/guest_quote_with_addresses.php
     * @magentoAppArea frontend
     * @return void
     */
    public function testTwoRefundsThatBothReadTheOpenOrderMakeOneCreditMemo(): void
    {
        $salableBefore = $this->salableQuantity();
        [, $orderId] = $this->paidTestOrder();
        $first = $this->copyOfTheOrder($orderId);
        $second = $this->copyOfTheOrder($orderId);
        self::assertNotSame($first, $second);
        self::assertSame(Order::STATE_PROCESSING, $second->getState());
        $refund = $this->service(PaidTestOrderRefundInterface::class);

        self::assertSame(Order::STATE_CLOSED, $refund->refund($first)->getState());
        self::assertSame(Order::STATE_CLOSED, $refund->refund($second)->getState());

        self::assertSame(1, $this->creditmemoCount($orderId));
        self::assertSame($salableBefore, $this->salableQuantity());
        self::assertSame(Order::STATE_CLOSED, $this->copyOfTheOrder($orderId)->getState());
    }

    /**
     * Two abandons that both read the open order: the one that loses the race holds a copy from before the refund, and
     * still answers the order as it stands, closed, with no warning that a person must end it.
     *
     * @magentoDataFixture Magento/Sales/_files/guest_quote_with_addresses.php
     * @magentoAppArea frontend
     * @return void
     */
    public function testTheAbandonThatLosesARaceAnswersClosedAndWarnsNobody(): void
    {
        [$quoteId, $orderId] = $this->paidTestOrder();
        $objectManager = Bootstrap::getObjectManager();
        $stale = $objectManager->create(OrderRepositoryInterface::class);
        self::assertInstanceOf(OrderRepositoryInterface::class, $stale);
        self::assertSame(Order::STATE_PROCESSING, $stale->get($orderId)->getState());
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('warning');
        $losing = $objectManager->create(AbandonTestOrder::class, ['orderRepository' => $stale, 'logger' => $logger]);
        self::assertInstanceOf(AbandonTestOrderInterface::class, $losing);

        $this->abandonTestOrder()->abandon($quoteId);
        $result = $losing->abandon($quoteId);

        self::assertSame(Order::STATE_CLOSED, $result->getState());
        self::assertSame(1, $this->creditmemoCount($orderId));
    }

    /**
     * Abandoning the closed order again answers the same, logs nothing, makes no second credit memo and returns no
     * stock twice, and the clean-up job's selection names the cart before the order is ended and never after.
     *
     * @magentoDataFixture Magento/Sales/_files/guest_quote_with_addresses.php
     * @magentoConfigFixture default/test_order/cleanup/delete_cancelled 1
     * @magentoAppArea frontend
     * @return void
     */
    public function testAClosedTestOrderStaysEndedAndLeavesTheCleanUpJob(): void
    {
        $salableBefore = $this->salableQuantity();
        [$quoteId, $orderId] = $this->paidTestOrder();
        self::assertContains($quoteId, $this->namedByTheCleanUpJob(deletesCancelled: true));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method(self::anything());
        $abandonTestOrder = $this->abandonTestOrder($logger);

        $first = $abandonTestOrder->abandon($quoteId);
        $second = $abandonTestOrder->abandon($quoteId);

        self::assertSame(
            [$first->getState(), $first->getIncrementId(), $first->isDeleted()],
            [$second->getState(), $second->getIncrementId(), $second->isDeleted()]
        );
        self::assertSame(Order::STATE_CLOSED, $second->getState());
        self::assertSame(1, $this->creditmemoCount($orderId));
        self::assertSame($salableBefore, $this->salableQuantity());
        self::assertNotContains($quoteId, $this->namedByTheCleanUpJob(deletesCancelled: true));
        self::assertNotContains($quoteId, $this->namedByTheCleanUpJob(deletesCancelled: false));
    }

    /**
     * On a shop that declares live payments a paid order is not refunded: it stays open, with its stock held and no
     * credit memo, one warning asks a person to end it, and the clean-up job does not come back for it.
     *
     * @magentoDataFixture Magento/Sales/_files/guest_quote_with_addresses.php
     * @magentoAppArea frontend
     * @return void
     */
    public function testOnDeclaredLivePaymentsAPaidOrderStaysOpenAndIsReported(): void
    {
        putenv(self::OVERRIDE_VARIABLE . '=' . json_encode(['test_order' => ['live_payments' => true]]));
        $this->service(DeploymentConfig::class)->resetData();
        [$quoteId, $orderId] = $this->paidTestOrder();
        $salable = $this->salableQuantity();
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning')->with(self::stringContains('person'));

        $result = $this->abandonTestOrder($logger)->abandon($quoteId);

        self::assertSame(Order::STATE_PROCESSING, $result->getState());
        self::assertFalse($result->isDeleted());
        self::assertSame(
            Order::STATE_PROCESSING,
            $this->service(OrderRepositoryInterface::class)->get($orderId)->getState()
        );
        self::assertSame(0, $this->creditmemoCount($orderId));
        self::assertSame($salable, $this->salableQuantity());
        self::assertNotContains($quoteId, $this->namedByTheCleanUpJob(deletesCancelled: false));
    }

    /**
     * An order closed before anyone abandoned it was never undone. The clean-up job names its cart, ending it undoes
     * it without a second refund, and the job is done with it: it holds no place in the batch for ever.
     *
     * @magentoDataFixture Magento/Sales/_files/guest_quote_with_addresses.php
     * @magentoAppArea frontend
     * @return void
     */
    public function testAnOrderClosedBeforeItWasAbandonedLeavesTheCleanUpJobOnceUndone(): void
    {
        [$quoteId, $orderId] = $this->paidTestOrder();
        $orders = $this->service(OrderRepositoryInterface::class);
        $refund = $this->service(PaidTestOrderRefundInterface::class);
        self::assertSame(Order::STATE_CLOSED, $refund->refund($orders->get($orderId))->getState());
        self::assertSame(Order::STATE_CLOSED, $orders->get($orderId)->getState());
        self::assertContains($quoteId, $this->namedByTheCleanUpJob(deletesCancelled: false));

        $result = $this->abandonTestOrder()->abandon($quoteId);

        self::assertSame(Order::STATE_CLOSED, $result->getState());
        self::assertSame(1, $this->creditmemoCount($orderId));
        self::assertNotContains($quoteId, $this->namedByTheCleanUpJob(deletesCancelled: false));
    }

    /**
     * A refund that fails leaves the order not undone, so the clean-up job still names the cart and counts the attempt.
     * With the refund working again, its next run ends the order and it names the cart no more.
     *
     * @magentoDataFixture Magento/Sales/_files/guest_quote_with_addresses.php
     * @magentoAppArea frontend
     * @return void
     */
    public function testARefundThatFailsIsTriedAgainByTheCleanUpJob(): void
    {
        [$quoteId, $orderId] = $this->paidTestOrder();
        $this->ageTheRecord($quoteId);
        $this->loadArea(Area::AREA_CRONTAB);
        $failingRefund = $this->createMock(PaidTestOrderRefundInterface::class);
        $failingRefund->expects(self::once())->method('refund')
            ->willThrowException(new LocalizedException(__('The refund failed.')));
        $objectManager = Bootstrap::getObjectManager();
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error')->with(self::stringContains('cart ' . $quoteId));

        $failingRun = $objectManager->create(AbandonStaleTestOrders::class, [
            'abandonTestOrder' => $objectManager->create(
                AbandonTestOrder::class,
                ['paidTestOrderRefund' => $failingRefund]
            ),
            'logger' => $logger,
        ]);
        self::assertInstanceOf(AbandonStaleTestOrders::class, $failingRun);
        $failingRun->execute();

        self::assertSame(1, $this->abandonAttempts($quoteId));
        self::assertContains($quoteId, $this->namedByTheCleanUpJob(deletesCancelled: false));
        self::assertSame(0, $this->creditmemoCount($orderId));

        $this->service(AbandonStaleTestOrders::class)->execute();

        self::assertSame(
            Order::STATE_CLOSED,
            $this->service(OrderRepositoryInterface::class)->get($orderId)->getState()
        );
        self::assertSame(1, $this->creditmemoCount($orderId));
        self::assertNotContains($quoteId, $this->namedByTheCleanUpJob(deletesCancelled: false));
    }

    /**
     * Turn the fixture's guest cart into a recognised cart, place it with no proof as a later placement does, and
     * invoice the order, which leaves it paid.
     *
     * @return array{int, int} The cart's entity id and the order's
     */
    private function paidTestOrder(): array
    {
        $resource = $this->service(ResourceConnection::class);
        $connection = $resource->getConnection();
        $quoteId = $connection->fetchOne(
            $connection->select()
                ->from($resource->getTableName('quote'), ['entity_id'])
                ->where('reserved_order_id = ?', self::FIXTURE_ORDER)
        );
        self::assertIsNumeric($quoteId);
        $quoteId = (int)$quoteId;

        $this->service(TestCartRecordsInterface::class)->recognise($quoteId);
        $this->loadArea(Area::AREA_FRONTEND);
        $orderId = (int)$this->service(CartManagementInterface::class)->placeOrder($quoteId);
        self::assertGreaterThan(0, $orderId);
        self::assertTrue($this->service(TestOrdersInterface::class)->isTestOrderId($orderId));

        $this->service(InvoiceOrderInterface::class)->execute($orderId);

        return [$quoteId, $orderId];
    }

    /**
     * Have every payment read from here on use a method that takes refunds online and fails the test if it is asked
     * to. The refund reads its own copy of the order, so the method cannot be set on one the test holds. The method is
     * asked whether it can refund, which proves it is the one the credit memo reached.
     *
     * @return void
     */
    private function giveAPaymentMethodThatMustNotRefund(): void
    {
        $paymentMethod = $this->createMock(MethodInterface::class);
        $paymentMethod->expects(self::atLeastOnce())->method('canRefund')->willReturn(true);
        $paymentMethod->method('isOffline')->willReturn(false);
        $paymentMethod->expects(self::never())->method('refund');
        $paymentData = $this->createMock(PaymentData::class);
        $paymentData->method('getMethodInstance')->willReturn($paymentMethod);
        $this->objectManager()->addSharedInstance($paymentData, PaymentData::class);
    }

    /**
     * The order as a request of its own reads it: from the database, past what this request's repository remembers.
     *
     * @param int $orderId Order entity id
     * @return Order
     */
    private function copyOfTheOrder(int $orderId): Order
    {
        $order = Bootstrap::getObjectManager()->create(Order::class);
        self::assertInstanceOf(Order::class, $order);
        $this->service(OrderResource::class)->load($order, $orderId);

        return $order;
    }

    /**
     * Make the cart's record old enough for the clean-up job.
     *
     * @param int $quoteId Cart (quote) entity id
     * @return void
     */
    private function ageTheRecord(int $quoteId): void
    {
        $resource = $this->service(ResourceConnection::class);
        $resource->getConnection()->update(
            $resource->getTableName(TestOrderRepository::TABLE_NAME),
            [TestOrderInterface::RECOGNISED_AT => self::LONG_AGO],
            [TestOrderInterface::QUOTE_ID . ' = ?' => $quoteId]
        );
    }

    /**
     * How often the clean-up job failed on the cart.
     *
     * @param int $quoteId Cart (quote) entity id
     * @return int
     */
    private function abandonAttempts(int $quoteId): int
    {
        return $this->service(TestOrderRepositoryInterface::class)->getByQuoteId($quoteId)->getAbandonAttempts();
    }

    /**
     * The use case from the container's wiring.
     *
     * @param LoggerInterface|null $logger Where it reports; the container's when null
     * @return AbandonTestOrderInterface
     */
    private function abandonTestOrder(?LoggerInterface $logger = null): AbandonTestOrderInterface
    {
        if ($logger === null) {
            return $this->service(AbandonTestOrderInterface::class);
        }

        $abandonTestOrder = Bootstrap::getObjectManager()->create(AbandonTestOrder::class, ['logger' => $logger]);
        self::assertInstanceOf(AbandonTestOrderInterface::class, $abandonTestOrder);

        return $abandonTestOrder;
    }

    /**
     * The carts the clean-up job would take up now.
     *
     * @param bool $deletesCancelled Whether cancelled orders are still to be deleted
     * @return list<int> Cart (quote) entity ids
     */
    private function namedByTheCleanUpJob(bool $deletesCancelled): array
    {
        return $this->service(StaleTestCartsInterface::class)->getStaleQuoteIds(0, 5, 1000, $deletesCancelled);
    }

    /**
     * How much of the fixture's product can still be sold now, past what the shop remembered for this request.
     *
     * @return float
     */
    private function salableQuantity(): float
    {
        $this->service(GetReservationsQuantityCache::class)->_resetState();

        return $this->service(GetProductSalableQtyInterface::class)->execute(self::FIXTURE_SKU, self::DEFAULT_STOCK);
    }

    /**
     * How much of the fixture's product the shop's sources hold.
     *
     * @return float
     */
    private function quantityAtSources(): float
    {
        $quantity = 0.0;
        foreach ($this->service(GetSourceItemsBySkuInterface::class)->execute(self::FIXTURE_SKU) as $sourceItem) {
            $quantity += (float)$sourceItem->getQuantity();
        }

        return $quantity;
    }

    /**
     * How many credit memos the order has.
     *
     * @param int $orderId Order entity id
     * @return int
     */
    private function creditmemoCount(int $orderId): int
    {
        $resource = $this->service(ResourceConnection::class);
        $connection = $resource->getConnection();
        $count = $connection->fetchOne(
            $connection->select()
                ->from($resource->getTableName('sales_creditmemo'), ['COUNT(*)'])
                ->where('order_id = ?', $orderId)
        );
        self::assertIsNumeric($count);

        return (int)$count;
    }

    /**
     * Load an area, as the data fixture leaves another one behind.
     *
     * @param string $area Area code
     * @return void
     */
    private function loadArea(string $area): void
    {
        Bootstrap::getInstance()->getBootstrap()->getApplication()->loadArea($area);
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
     * A service from the container.
     *
     * @template T of object
     * @param class-string<T> $type Service type
     * @return T
     */
    private function service(string $type): object
    {
        $service = Bootstrap::getObjectManager()->get($type);
        self::assertInstanceOf($type, $service);

        return $service;
    }
}
