<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\TestOrder\Test\Unit\Model\EventReader;

use Hryvinskyi\TestOrder\Test\Unit\Fixture\BuildsEventReaders;
use Magento\Framework\DataObject;
use Magento\Framework\Event;
use Magento\Quote\Api\Data\AddressInterface as QuoteAddressInterface;
use Magento\Quote\Api\Data\CartInterface;
use Magento\Quote\Api\Data\CartItemInterface;
use Magento\Sales\Api\Data\OrderAddressInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\Data\OrderItemInterface;
use Magento\Sales\Api\Data\OrderPaymentInterface;
use Magento\Sales\Model\Order\Invoice;
use Magento\Sales\Model\Order\Payment;
use PHPUnit\Framework\TestCase;

/**
 * Each reader reports only what its key carries in the shape it understands, and reads nothing it would have to load.
 */
class EventReadersTest extends TestCase
{
    use BuildsEventReaders;

    /**
     * The order reader hands over the order object as carried, even without an id.
     *
     * @return void
     */
    public function testTheOrderIsHandedOverAsCarried(): void
    {
        $order = $this->createMock(OrderInterface::class);
        $order->method('getEntityId')->willReturn(null);

        $found = $this->eventReaders()['order']->read(new Event(['order' => $order]));

        self::assertSame([$order], $found->getOrders());
        self::assertSame([], $found->getOrderIds());
    }

    /**
     * The success page's list keeps integers and digit strings and drops everything else.
     *
     * @return void
     */
    public function testOrderIdsAreReadFromIntegersAndDigitStringsOnly(): void
    {
        $found = $this->eventReaders()['order_ids']->read(
            new Event(['order_ids' => ['12', 13, 'x', '', '-4', 0, -1, null, 1.5, '7a']])
        );

        self::assertSame([12, 13], $found->getOrderIds());
        self::assertSame([], $found->getOrders());
    }

    /**
     * Something under a reader's key that is not the shape it reads is not read.
     *
     * @dataProvider foreignShapes
     * @param string $reader Pool key of the reader
     * @param string $dataKey Event data key
     * @param class-string|null $contract Contract of what is carried; null for a plain data object
     * @return void
     */
    public function testAForeignShapeUnderTheKeyIsNotRead(string $reader, string $dataKey, ?string $contract): void
    {
        $carried = $contract === null
            ? new DataObject(['order_id' => 5, 'parent_id' => 5])
            : $this->getMockBuilder($contract)->getMock();

        self::assertTrue($this->eventReaders()[$reader]->read(new Event([$dataKey => $carried]))->isEmpty());
    }

    /**
     * Shapes that share a key with an order shape.
     *
     * @return array<string, array{string, string, class-string|null}>
     */
    public static function foreignShapes(): array
    {
        return [
            'a quote item under item' => ['item', 'item', CartItemInterface::class],
            'a quote address under address' => ['address', 'address', QuoteAddressInterface::class],
            'a quote address as the saved object' => ['address_data_object', 'data_object', QuoteAddressInterface::class],
            'a plain object under order' => ['order', 'order', null],
            'a plain object under payment' => ['payment', 'payment', null],
            'a plain object under status history' => ['status_history', 'status_history', null],
            'a plain object under quote' => ['quote', 'quote', null],
        ];
    }

    /**
     * The cart reader reports the cart by its id and nothing else.
     *
     * @return void
     */
    public function testTheCartIsReportedByItsId(): void
    {
        $cart = $this->createMock(CartInterface::class);
        $cart->method('getId')->willReturn(42);

        $found = $this->eventReaders()['quote']->read(new Event(['quote' => $cart]));

        self::assertSame([42], $found->getQuoteIds());
        self::assertSame([], $found->getOrders());
        self::assertSame([], $found->getOrderIds());
    }

    /**
     * A cart that has not been saved names no cart record, and is not read.
     *
     * @return void
     */
    public function testAnUnsavedCartIsNotRead(): void
    {
        $cart = $this->createMock(CartInterface::class);
        $cart->method('getId')->willReturn(null);

        self::assertTrue($this->eventReaders()['quote']->read(new Event(['quote' => $cart]))->isEmpty());
    }

    /**
     * Something that does not name its order yet, and holds no order object to answer for it, is not read.
     *
     * @return void
     */
    public function testWhatNamesNoOrderYetIsNotRead(): void
    {
        $payment = $this->createMock(OrderPaymentInterface::class);
        $payment->method('getParentId')->willReturn(null);
        $item = $this->createMock(OrderItemInterface::class);
        $item->method('getOrderId')->willReturn(0);

        $readers = $this->eventReaders();

        self::assertTrue($readers['payment']->read(new Event(['payment' => $payment]))->isEmpty());
        self::assertTrue($readers['item']->read(new Event(['item' => $item]))->isEmpty());
    }

    /**
     * The payment of an order being placed names no order yet, so the order object it holds is handed over, to be
     * answered by its cart.
     *
     * @return void
     */
    public function testThePaymentOfAnOrderBeingPlacedHandsOverItsOrder(): void
    {
        $order = $this->createStub(OrderInterface::class);
        $payment = $this->createMock(Payment::class);
        $payment->method('getParentId')->willReturn(null);
        $payment->method('getOrder')->willReturn($order);

        $found = $this->eventReaders()['payment']->read(new Event(['payment' => $payment]));

        self::assertSame([$order], $found->getOrders());
        self::assertSame([], $found->getOrderIds());
    }

    /**
     * A payment that names its order is read by that id, and nothing is asked of its order object.
     *
     * @return void
     */
    public function testAPaymentThatNamesItsOrderIsReadByTheId(): void
    {
        $payment = $this->createMock(Payment::class);
        $payment->method('getParentId')->willReturn(812);
        $payment->expects(self::never())->method('getOrder');

        $found = $this->eventReaders()['payment']->read(new Event(['payment' => $payment]));

        self::assertSame([812], $found->getOrderIds());
        self::assertSame([], $found->getOrders());
    }

    /**
     * An invoice issued while its order is placed names no order yet, so the order object it holds is handed over.
     *
     * @return void
     */
    public function testAnInvoiceIssuedWhileItsOrderIsPlacedHandsOverItsOrder(): void
    {
        $order = $this->createStub(OrderInterface::class);
        $invoice = $this->createMock(Invoice::class);
        $invoice->method('getOrderId')->willReturn(null);
        $invoice->method('hasData')->with('order_id')->willReturn(true);
        $invoice->method('getOrder')->willReturn($order);

        $found = $this->eventReaders()['invoice']->read(new Event(['invoice' => $invoice]));

        self::assertSame([$order], $found->getOrders());
        self::assertSame([], $found->getOrderIds());
    }

    /**
     * An invoice given no order yet holds none, and asking it would build an empty one and keep it: such an invoice is
     * not read, so the plugin's warning about an event nobody can read still fires.
     *
     * @return void
     */
    public function testAnInvoiceGivenNoOrderIsNotAskedForOne(): void
    {
        $invoice = $this->createMock(Invoice::class);
        $invoice->method('getOrderId')->willReturn(null);
        $invoice->method('hasData')->willReturn(false);
        $invoice->expects(self::never())->method('getOrder');

        self::assertTrue($this->eventReaders()['invoice']->read(new Event(['invoice' => $invoice]))->isEmpty());
    }

    /**
     * An invoice that names its order is read by that id, and nothing is asked of its order object.
     *
     * @return void
     */
    public function testAnInvoiceThatNamesItsOrderIsReadByTheId(): void
    {
        $invoice = $this->createMock(Invoice::class);
        $invoice->method('getOrderId')->willReturn(812);
        $invoice->expects(self::never())->method('getOrder');

        $found = $this->eventReaders()['invoice']->read(new Event(['invoice' => $invoice]));

        self::assertSame([812], $found->getOrderIds());
        self::assertSame([], $found->getOrders());
    }

    /**
     * The address reader reads only the key it is wired to.
     *
     * @return void
     */
    public function testTheAddressReaderReadsOnlyItsKey(): void
    {
        $address = $this->createMock(OrderAddressInterface::class);
        $address->method('getParentId')->willReturn(31);
        $readers = $this->eventReaders();

        self::assertSame([31], $readers['address']->read(new Event(['address' => $address]))->getOrderIds());
        self::assertTrue($readers['address']->read(new Event(['data_object' => $address]))->isEmpty());
        self::assertSame(
            [31],
            $readers['address_data_object']->read(new Event(['data_object' => $address]))->getOrderIds()
        );
    }

    /**
     * An event with nothing about orders is empty for every reader.
     *
     * @return void
     */
    public function testAnEventWithoutOrdersIsEmptyForEveryReader(): void
    {
        foreach ($this->eventReaders() as $name => $reader) {
            self::assertTrue($reader->read(new Event(['product' => new DataObject()]))->isEmpty(), $name);
        }
    }
}
