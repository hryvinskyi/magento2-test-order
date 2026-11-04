<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\TestOrder\Test\Unit\Plugin;

use Hryvinskyi\TestOrder\Plugin\SkipObserverForTestOrders;
use Hryvinskyi\TestOrder\Test\Unit\Fixture\BuildsEventReaders;
use Hryvinskyi\TestOrder\Test\Unit\Fixture\InMemoryTestOrders;
use Hryvinskyi\TestOrder\Test\Unit\Plugin\Fixture\GuardedObserver;
use Magento\Framework\DataObject;
use Magento\Framework\Event;
use Magento\Framework\Event\Observer;
use Magento\Quote\Api\Data\CartInterface;
use Magento\Quote\Api\Data\CartItemInterface;
use Magento\Sales\Api\Data\CreditmemoInterface;
use Magento\Sales\Api\Data\InvoiceInterface;
use Magento\Sales\Api\Data\OrderAddressInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\Data\OrderItemInterface;
use Magento\Sales\Api\Data\OrderPaymentInterface;
use Magento\Sales\Api\Data\OrderStatusHistoryInterface;
use Magento\Sales\Api\Data\ShipmentInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * A guarded observer does not run for an event about a test order, whichever shape the event has — including a cart
 * checked out before its order exists; it runs for an ordinary order; and it runs, with a warning naming it and the
 * event, when no order can be found in the event.
 */
class SkipObserverForTestOrdersTest extends TestCase
{
    use BuildsEventReaders;

    private const int TEST_QUOTE_ID = 501;

    private const int ORDINARY_QUOTE_ID = 502;

    private const int TEST_ORDER_ID = 9001;

    private const int ORDINARY_ORDER_ID = 9002;

    private const string ORDER_IDS = 'order_ids';

    /**
     * @var LoggerInterface&MockObject
     */
    private LoggerInterface&MockObject $logger;

    /**
     * @var GuardedObserver
     */
    private GuardedObserver $observer;

    /**
     * @inheritDoc
     */
    protected function setUp(): void
    {
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->observer = new GuardedObserver();
    }

    /**
     * An event about a test order does not reach the observer, and nothing is logged.
     *
     * @dataProvider eventShapes
     * @param string $eventName Event name
     * @param string $dataKey Key the order or what names it is carried under
     * @param string $carried What is carried: an order contract, `order_ids`, the cart contract, or the contract of a
     *     child of an order
     * @param string|null $orderIdGetter Method returning the order id; null for an order still being placed or a cart
     * @return void
     */
    public function testAnEventAboutATestOrderDoesNotReachTheObserver(
        string $eventName,
        string $dataKey,
        string $carried,
        ?string $orderIdGetter
    ): void {
        $this->logger->expects(self::never())->method('warning');

        $this->guard($this->event($eventName, [
            $dataKey => $this->carried($carried, $orderIdGetter, self::TEST_QUOTE_ID, self::TEST_ORDER_ID),
        ]));

        self::assertSame(0, $this->observer->runs);
    }

    /**
     * An event about an ordinary order reaches the observer, and nothing is logged.
     *
     * @dataProvider eventShapes
     * @param string $eventName Event name
     * @param string $dataKey Key the order or what names it is carried under
     * @param string $carried What is carried: an order contract, `order_ids`, the cart contract, or the contract of a
     *     child of an order
     * @param string|null $orderIdGetter Method returning the order id; null for an order still being placed or a cart
     * @return void
     */
    public function testAnEventAboutAnOrdinaryOrderReachesTheObserver(
        string $eventName,
        string $dataKey,
        string $carried,
        ?string $orderIdGetter
    ): void {
        $this->logger->expects(self::never())->method('warning');

        $this->guard($this->event($eventName, [
            $dataKey => $this->carried($carried, $orderIdGetter, self::ORDINARY_QUOTE_ID, self::ORDINARY_ORDER_ID),
        ]));

        self::assertSame(1, $this->observer->runs);
    }

    /**
     * Every event shape an opted-out observer listens to.
     *
     * @return array<string, array{string, string, string, string|null}>
     */
    public static function eventShapes(): array
    {
        return [
            'order still being placed, no id yet' => [
                'sales_order_place_after', 'order', OrderInterface::class, null,
            ],
            'saved order' => ['sales_order_save_after', 'order', OrderInterface::class, 'getEntityId'],
            'success page order ids' => [
                'checkout_onepage_controller_success_action', 'order_ids', self::ORDER_IDS, 'getEntityId',
            ],
            'order item' => ['sales_order_item_save_after', 'item', OrderItemInterface::class, 'getOrderId'],
            'payment' => ['sales_order_payment_save_after', 'payment', OrderPaymentInterface::class, 'getParentId'],
            'invoice' => ['sales_order_invoice_save_after', 'invoice', InvoiceInterface::class, 'getOrderId'],
            'credit memo' => [
                'sales_order_creditmemo_save_after', 'creditmemo', CreditmemoInterface::class, 'getOrderId',
            ],
            'shipment' => ['sales_order_shipment_save_after', 'shipment', ShipmentInterface::class, 'getOrderId'],
            'order address' => [
                'sales_order_address_save_after', 'address', OrderAddressInterface::class, 'getParentId',
            ],
            'order address as the saved object only' => [
                'sales_order_address_save_after', 'data_object', OrderAddressInterface::class, 'getParentId',
            ],
            'status history entry' => [
                'sales_order_status_history_save_after',
                'status_history',
                OrderStatusHistoryInterface::class,
                'getParentId',
            ],
            'cart being checked out, no order yet' => ['checkout_submit_before', 'quote', CartInterface::class, null],
        ];
    }

    /**
     * One ordinary order in the event does not let the observer act on a test order in the same event.
     *
     * @return void
     */
    public function testATestOrderAmongOrdinaryOnesStillKeepsTheObserverAway(): void
    {
        $this->guard($this->event('sales_order_save_after', [
            'order' => $this->order(self::ORDINARY_QUOTE_ID, self::ORDINARY_ORDER_ID),
            'status_history' => $this->statusHistory(self::TEST_ORDER_ID),
        ]));

        self::assertSame(0, $this->observer->runs);
    }

    /**
     * An order that names no cart is an ordinary order: the observer runs, and nothing is logged.
     *
     * @return void
     */
    public function testAnOrderWithoutACartReachesTheObserverWithoutAWarning(): void
    {
        $this->logger->expects(self::never())->method('warning');

        $this->guard($this->event('sales_order_place_after', ['order' => $this->order(null, null)]));

        self::assertSame(1, $this->observer->runs);
    }

    /**
     * An event in which no order can be found still reaches the observer, and is reported with the observer's own
     * class (not its interceptor's) and the event's name.
     *
     * @return void
     */
    public function testAnEventWithoutAnOrderReachesTheObserverAndIsReported(): void
    {
        $observer = new GuardedObserver\Interceptor();
        $this->logger->expects(self::once())
            ->method('warning')
            ->with(
                self::isType('string'),
                ['observer' => GuardedObserver::class, 'event' => 'sales_quote_item_save_after']
            );

        $this->guard(
            $this->event('sales_quote_item_save_after', [
                'item' => $this->createMock(CartItemInterface::class),
                'data_object' => new DataObject(['parent_id' => self::TEST_ORDER_ID]),
            ]),
            $observer
        );

        self::assertSame(1, $observer->runs);
    }

    /**
     * An item of the pool that is no event reader is refused by name when the plugin is built, not when an event
     * comes in.
     *
     * @return void
     */
    public function testAPoolItemThatIsNoReaderIsRefusedByName(): void
    {
        $testOrders = new InMemoryTestOrders();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('return_request');

        new SkipObserverForTestOrders($testOrders, $testOrders, $this->logger, ['return_request' => new \stdClass()]);
    }

    /**
     * Without any reader nothing can be found: the observer runs and the event is reported.
     *
     * @return void
     */
    public function testWithoutReadersEveryEventIsReported(): void
    {
        $this->logger->expects(self::once())->method('warning');
        $testOrders = new InMemoryTestOrders([self::TEST_QUOTE_ID]);
        $plugin = new SkipObserverForTestOrders($testOrders, $testOrders, $this->logger);

        $plugin->aroundExecute(
            $this->observer,
            fn (Observer $observer) => $this->observer->execute($observer),
            $this->event('sales_order_place_after', ['order' => $this->order(self::TEST_QUOTE_ID, null)])
        );

        self::assertSame(1, $this->observer->runs);
    }

    /**
     * Pass the event through the plugin, with the module's readers, to the observer.
     *
     * @param Observer $event Observed event
     * @param GuardedObserver|null $observer Observer guarded; the test's own when null
     * @return void
     */
    private function guard(Observer $event, ?GuardedObserver $observer = null): void
    {
        $observer ??= $this->observer;
        $testOrders = new InMemoryTestOrders([self::TEST_QUOTE_ID], [self::TEST_ORDER_ID]);
        $plugin = new SkipObserverForTestOrders($testOrders, $testOrders, $this->logger, $this->eventReaders());

        $plugin->aroundExecute($observer, static fn (Observer $passed) => $observer->execute($passed), $event);
    }

    /**
     * An observed event, as the event manager hands it to an observer.
     *
     * @param string $name Event name
     * @param array<string, mixed> $data Event data
     * @return Observer
     */
    private function event(string $name, array $data): Observer
    {
        $event = new Event($data);
        $event->setName($name);

        return new Observer(array_merge(['event' => $event], $data));
    }

    /**
     * What an event carries about an order.
     *
     * @param string $carried An order contract, `order_ids`, the cart contract, or the contract of a child of an order
     * @param string|null $orderIdGetter Method returning the order id; null for an order still being placed
     * @param int $quoteId Cart the order was placed from
     * @param int $orderId The order's id
     * @return mixed
     */
    private function carried(string $carried, ?string $orderIdGetter, int $quoteId, int $orderId): mixed
    {
        if ($carried === self::ORDER_IDS) {
            return [(string)$orderId];
        }

        if ($carried === CartInterface::class) {
            return $this->cart($quoteId);
        }

        if ($carried === OrderInterface::class) {
            return $this->order($quoteId, $orderIdGetter === null ? null : $orderId);
        }

        if (!interface_exists($carried)) {
            self::fail(sprintf('%s is not a contract a double can be made of.', $carried));
        }

        $child = $this->getMockBuilder($carried)->getMock();
        $child->method((string)$orderIdGetter)->willReturn($orderId);

        return $child;
    }

    /**
     * An order placed from a cart.
     *
     * @param int|null $quoteId Cart it names
     * @param int|null $orderId Its id; null while it is being placed
     * @return OrderInterface
     */
    private function order(?int $quoteId, ?int $orderId): OrderInterface
    {
        $order = $this->createMock(OrderInterface::class);
        $order->method('getQuoteId')->willReturn($quoteId);
        $order->method('getEntityId')->willReturn($orderId);

        return $order;
    }

    /**
     * A saved cart being checked out.
     *
     * @param int $quoteId Its id
     * @return CartInterface
     */
    private function cart(int $quoteId): CartInterface
    {
        $cart = $this->createMock(CartInterface::class);
        $cart->method('getId')->willReturn($quoteId);

        return $cart;
    }

    /**
     * A status-history entry written on an order.
     *
     * @param int $orderId The order's id
     * @return OrderStatusHistoryInterface
     */
    private function statusHistory(int $orderId): OrderStatusHistoryInterface
    {
        $entry = $this->createMock(OrderStatusHistoryInterface::class);
        $entry->method('getParentId')->willReturn($orderId);

        return $entry;
    }
}
