<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\TestOrder\Test\Integration\Plugin;

use Hryvinskyi\TestOrder\Api\TestCartRecordsInterface;
use Hryvinskyi\TestOrder\Plugin\SkipObserverForTestOrders;
use Hryvinskyi\TestOrder\Test\Integration\Fixture\RecordingObserver;
use Hryvinskyi\TestOrder\Test\Integration\OrderRows;
use Magento\Framework\Event;
use Magento\Framework\Event\Observer;
use Magento\Quote\Api\Data\CartInterface;
use Magento\Sales\Api\Data\CreditmemoInterface;
use Magento\Sales\Api\Data\InvoiceInterface;
use Magento\Sales\Api\Data\OrderAddressInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\Data\OrderItemInterface;
use Magento\Sales\Api\Data\OrderPaymentInterface;
use Magento\Sales\Api\Data\OrderStatusHistoryInterface;
use Magento\Sales\Api\Data\ShipmentInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Invoice;
use Magento\Sales\Model\Order\Payment;
use Magento\TestFramework\Helper\Bootstrap;
use PHPUnit\Framework\TestCase;

/**
 * The plugin as the container builds it — the module's reader pool and the real answers from the test-cart records —
 * keeps an observer away from a test order in every event shape the readers know, a cart checked out before its
 * order exists included, and lets it act on an ordinary
 * order. The sales objects are the real ones, so a reader that read the wrong field would be caught here.
 *
 * @magentoDbIsolation enabled
 * @magentoAppIsolation enabled
 * @magentoAppArea global
 */
class SkipObserverForTestOrdersTest extends TestCase
{
    private const int TEST_QUOTE_ID = 987301;

    private const int ORDINARY_QUOTE_ID = 987302;

    /**
     * An event about a test order does not reach the observer; the same event about an ordinary order does.
     *
     * @dataProvider eventShapes
     * @param string $eventName Event name
     * @param string $dataKey Key the order, or what names it, is carried under
     * @param string $shape What is carried
     * @return void
     */
    public function testOnlyAnEventAboutAnOrdinaryOrderReachesTheObserver(
        string $eventName,
        string $dataKey,
        string $shape
    ): void {
        $this->records()->recognise(self::TEST_QUOTE_ID);
        $testOrderId = OrderRows::insert(self::TEST_QUOTE_ID);
        $ordinaryOrderId = OrderRows::insert(self::ORDINARY_QUOTE_ID);

        $aboutTestOrder = $this->guard(
            $eventName,
            [$dataKey => $this->carried($shape, self::TEST_QUOTE_ID, $testOrderId)]
        );
        $aboutOrdinaryOrder = $this->guard(
            $eventName,
            [$dataKey => $this->carried($shape, self::ORDINARY_QUOTE_ID, $ordinaryOrderId)]
        );

        self::assertSame(0, $aboutTestOrder->runs, 'The observer acted on a test order.');
        self::assertSame(1, $aboutOrdinaryOrder->runs, 'The observer did not act on an ordinary order.');
    }

    /**
     * Every event shape the module's readers know.
     *
     * @return array<string, array{string, string, string}>
     */
    public static function eventShapes(): array
    {
        return [
            'order still being placed' => ['sales_order_place_after', 'order', 'unsaved order'],
            'success page' => ['checkout_onepage_controller_success_action', 'order_ids', 'order ids'],
            'order item' => ['sales_order_item_save_after', 'item', OrderItemInterface::class],
            'payment' => ['sales_order_payment_save_after', 'payment', OrderPaymentInterface::class],
            'invoice' => ['sales_order_invoice_save_after', 'invoice', InvoiceInterface::class],
            'credit memo' => ['sales_order_creditmemo_save_after', 'creditmemo', CreditmemoInterface::class],
            'shipment' => ['sales_order_shipment_save_after', 'shipment', ShipmentInterface::class],
            'order address' => ['sales_order_address_save_after', 'address', OrderAddressInterface::class],
            'order address as the saved object' => [
                'sales_order_address_save_after', 'data_object', OrderAddressInterface::class,
            ],
            'status history' => [
                'sales_order_status_history_save_after', 'status_history', OrderStatusHistoryInterface::class,
            ],
            'cart checked out before its order exists' => ['checkout_submit_before', 'quote', CartInterface::class],
        ];
    }

    /**
     * The payment of an order being placed names no order yet, and its events carry nothing else: the order the
     * payment belongs to answers for it, so an opted-out observer ignores the order from the moment it is placed.
     *
     * @dataProvider paymentPlacementEvents
     * @param string $eventName Event dispatched while the payment is placed
     * @return void
     */
    public function testThePaymentOfAnOrderBeingPlacedIsAnsweredByItsOrder(string $eventName): void
    {
        $this->records()->recognise(self::TEST_QUOTE_ID);

        $aboutTestOrder = $this->guard($eventName, ['payment' => $this->placingPayment(self::TEST_QUOTE_ID)]);
        $aboutOrdinaryOrder = $this->guard($eventName, ['payment' => $this->placingPayment(self::ORDINARY_QUOTE_ID)]);

        self::assertSame(0, $aboutTestOrder->runs, 'The observer acted on a test order.');
        self::assertSame(1, $aboutOrdinaryOrder->runs, 'The observer did not act on an ordinary order.');
    }

    /**
     * An invoice issued while its order is placed, by a payment method that captures at placement, names no order yet
     * and its event carries nothing else: the order it holds answers for it.
     *
     * @return void
     */
    public function testAnInvoiceIssuedWhileItsOrderIsPlacedIsAnsweredByItsOrder(): void
    {
        $this->records()->recognise(self::TEST_QUOTE_ID);

        $aboutTestOrder = $this->guard(
            'sales_order_invoice_pay',
            ['invoice' => $this->placingInvoice(self::TEST_QUOTE_ID)]
        );
        $aboutOrdinaryOrder = $this->guard(
            'sales_order_invoice_pay',
            ['invoice' => $this->placingInvoice(self::ORDINARY_QUOTE_ID)]
        );

        self::assertSame(0, $aboutTestOrder->runs, 'The observer acted on a test order.');
        self::assertSame(1, $aboutOrdinaryOrder->runs, 'The observer did not act on an ordinary order.');
    }

    /**
     * @return array<string, array{string}>
     */
    public static function paymentPlacementEvents(): array
    {
        return [
            'placing starts' => ['sales_order_payment_place_start'],
            'placing ends' => ['sales_order_payment_place_end'],
        ];
    }

    /**
     * An event in which no order can be found still reaches the observer.
     *
     * @return void
     */
    public function testAnEventWithoutAnOrderReachesTheObserver(): void
    {
        self::assertSame(1, $this->guard('catalog_product_save_after', ['product' => null])->runs);
    }

    /**
     * Pass an event through the container's plugin to a fresh observer.
     *
     * @param string $eventName Event name
     * @param array<string, mixed> $data Event data
     * @return RecordingObserver The observer, to read how often it ran
     */
    private function guard(string $eventName, array $data): RecordingObserver
    {
        $plugin = Bootstrap::getObjectManager()->get(SkipObserverForTestOrders::class);
        self::assertInstanceOf(SkipObserverForTestOrders::class, $plugin);

        $event = new Event($data);
        $event->setName($eventName);
        $observer = new RecordingObserver();
        $plugin->aroundExecute(
            $observer,
            static fn (Observer $passed) => $observer->execute($passed),
            new Observer(array_merge(['event' => $event], $data))
        );

        return $observer;
    }

    /**
     * What an event carries about an order, made by the container like the real thing.
     *
     * @param string $shape `unsaved order`, `order ids`, the cart contract, or the contract of a child of an order
     * @param int $quoteId Cart the order was placed from
     * @param int $orderId The order's id
     * @return mixed
     */
    private function carried(string $shape, int $quoteId, int $orderId): mixed
    {
        return match ($shape) {
            'unsaved order' => $this->unsavedOrder($quoteId),
            'order ids' => [(string)$orderId],
            OrderItemInterface::class => $this->orderItem($orderId),
            OrderPaymentInterface::class => $this->payment($orderId),
            InvoiceInterface::class => $this->invoice($orderId),
            CreditmemoInterface::class => $this->creditmemo($orderId),
            ShipmentInterface::class => $this->shipment($orderId),
            OrderAddressInterface::class => $this->address($orderId),
            OrderStatusHistoryInterface::class => $this->statusHistory($orderId),
            CartInterface::class => $this->cart($quoteId),
            default => self::fail('Unknown event shape ' . $shape),
        };
    }

    /**
     * @param int $quoteId Cart it names
     * @return OrderInterface An order being placed: no id yet
     */
    private function unsavedOrder(int $quoteId): OrderInterface
    {
        $order = Bootstrap::getObjectManager()->create(OrderInterface::class);
        self::assertInstanceOf(OrderInterface::class, $order);
        $order->setQuoteId($quoteId);

        return $order;
    }

    /**
     * @param int $orderId Order it names
     * @return OrderItemInterface
     */
    private function orderItem(int $orderId): OrderItemInterface
    {
        $item = Bootstrap::getObjectManager()->create(OrderItemInterface::class);
        self::assertInstanceOf(OrderItemInterface::class, $item);
        $item->setOrderId($orderId);

        return $item;
    }

    /**
     * @param int $orderId Order it names
     * @return OrderPaymentInterface
     */
    private function payment(int $orderId): OrderPaymentInterface
    {
        $payment = Bootstrap::getObjectManager()->create(OrderPaymentInterface::class);
        self::assertInstanceOf(OrderPaymentInterface::class, $payment);
        $payment->setParentId($orderId);

        return $payment;
    }

    /**
     * A real payment of a real order that is still being placed: it names no order, and neither has an id.
     *
     * @param int $quoteId Cart the order is placed from
     * @return Payment
     */
    private function placingPayment(int $quoteId): Payment
    {
        $order = Bootstrap::getObjectManager()->create(Order::class);
        self::assertInstanceOf(Order::class, $order);
        $order->setQuoteId($quoteId);
        $payment = Bootstrap::getObjectManager()->create(Payment::class);
        self::assertInstanceOf(Payment::class, $payment);
        $payment->setOrder($order);

        return $payment;
    }

    /**
     * A real invoice of a real order that is still being placed: it names no order, and neither has an id.
     *
     * @param int $quoteId Cart the order is placed from
     * @return Invoice
     */
    private function placingInvoice(int $quoteId): Invoice
    {
        $order = Bootstrap::getObjectManager()->create(Order::class);
        self::assertInstanceOf(Order::class, $order);
        $order->setQuoteId($quoteId);
        $invoice = Bootstrap::getObjectManager()->create(Invoice::class);
        self::assertInstanceOf(Invoice::class, $invoice);
        $invoice->setOrder($order);

        return $invoice;
    }

    /**
     * @param int $orderId Order it names
     * @return InvoiceInterface
     */
    private function invoice(int $orderId): InvoiceInterface
    {
        $invoice = Bootstrap::getObjectManager()->create(InvoiceInterface::class);
        self::assertInstanceOf(InvoiceInterface::class, $invoice);
        $invoice->setOrderId($orderId);

        return $invoice;
    }

    /**
     * @param int $orderId Order it names
     * @return CreditmemoInterface
     */
    private function creditmemo(int $orderId): CreditmemoInterface
    {
        $creditmemo = Bootstrap::getObjectManager()->create(CreditmemoInterface::class);
        self::assertInstanceOf(CreditmemoInterface::class, $creditmemo);
        $creditmemo->setOrderId($orderId);

        return $creditmemo;
    }

    /**
     * @param int $orderId Order it names
     * @return ShipmentInterface
     */
    private function shipment(int $orderId): ShipmentInterface
    {
        $shipment = Bootstrap::getObjectManager()->create(ShipmentInterface::class);
        self::assertInstanceOf(ShipmentInterface::class, $shipment);
        $shipment->setOrderId($orderId);

        return $shipment;
    }

    /**
     * @param int $orderId Order it names
     * @return OrderAddressInterface
     */
    private function address(int $orderId): OrderAddressInterface
    {
        $address = Bootstrap::getObjectManager()->create(OrderAddressInterface::class);
        self::assertInstanceOf(OrderAddressInterface::class, $address);
        $address->setParentId($orderId);

        return $address;
    }

    /**
     * @param int $orderId Order it names
     * @return OrderStatusHistoryInterface
     */
    private function statusHistory(int $orderId): OrderStatusHistoryInterface
    {
        $entry = Bootstrap::getObjectManager()->create(OrderStatusHistoryInterface::class);
        self::assertInstanceOf(OrderStatusHistoryInterface::class, $entry);
        $entry->setParentId($orderId);

        return $entry;
    }

    /**
     * @param int $quoteId Its id
     * @return CartInterface A cart being checked out
     */
    private function cart(int $quoteId): CartInterface
    {
        $cart = Bootstrap::getObjectManager()->create(CartInterface::class);
        self::assertInstanceOf(CartInterface::class, $cart);
        $cart->setId($quoteId);

        return $cart;
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
