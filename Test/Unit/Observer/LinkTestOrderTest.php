<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\TestOrder\Test\Unit\Observer;

use Hryvinskyi\TestOrder\Api\TestCartRecordsInterface;
use Hryvinskyi\TestOrder\Observer\LinkTestOrder;
use Hryvinskyi\TestOrder\Test\Unit\Fixture\InMemoryTestOrders;
use Magento\Framework\DataObject;
use Magento\Framework\Event;
use Magento\Framework\Event\Observer;
use Magento\Sales\Api\Data\OrderInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * A placed test order is linked to its cart's record; an ordinary order, an order without an id or cart, and an event
 * without an order are never written for.
 */
class LinkTestOrderTest extends TestCase
{
    private const int TEST_QUOTE_ID = 61;

    private const int ORDER_ID = 7001;

    /**
     * @var TestCartRecordsInterface&MockObject
     */
    private TestCartRecordsInterface&MockObject $records;

    /**
     * @var InMemoryTestOrders
     */
    private InMemoryTestOrders $testOrders;

    /**
     * @inheritDoc
     */
    protected function setUp(): void
    {
        $this->records = $this->createMock(TestCartRecordsInterface::class);
        $this->testOrders = new InMemoryTestOrders([self::TEST_QUOTE_ID]);
    }

    /**
     * A placed test order is linked to its cart's record.
     *
     * @return void
     */
    public function testAPlacedTestOrderIsLinked(): void
    {
        $this->records->expects(self::once())
            ->method('linkOrder')
            ->with(self::TEST_QUOTE_ID, self::ORDER_ID);

        $this->observe(['order' => $this->order(self::TEST_QUOTE_ID, self::ORDER_ID)]);
    }

    /**
     * An ordinary order is asked about once and never written for.
     *
     * @return void
     */
    public function testAnOrdinaryOrderIsNotLinked(): void
    {
        $this->records->expects(self::never())->method('linkOrder');

        $this->observe(['order' => $this->order(62, self::ORDER_ID)]);

        self::assertSame(1, $this->testOrders->questions);
    }

    /**
     * An order without an id or without a cart cannot be linked, and is not even asked about.
     *
     * @dataProvider unlinkableOrders
     * @param int|null $quoteId Cart it names
     * @param int|null $orderId Its id
     * @return void
     */
    public function testAnOrderWithoutAnIdOrACartIsNotLinked(?int $quoteId, ?int $orderId): void
    {
        $this->records->expects(self::never())->method('linkOrder');

        $this->observe(['order' => $this->order($quoteId, $orderId)]);

        self::assertSame(0, $this->testOrders->questions);
    }

    /**
     * @return array<string, array{int|null, int|null}>
     */
    public static function unlinkableOrders(): array
    {
        return [
            'no id' => [self::TEST_QUOTE_ID, null],
            'no cart' => [null, self::ORDER_ID],
        ];
    }

    /**
     * An event that carries no order object writes nothing.
     *
     * @return void
     */
    public function testAnEventWithoutAnOrderIsIgnored(): void
    {
        $this->records->expects(self::never())->method('linkOrder');

        $this->observe(['order' => new DataObject(['entity_id' => self::ORDER_ID, 'quote_id' => self::TEST_QUOTE_ID])]);
        $this->observe([]);
    }

    /**
     * Run the observer for an event with the data.
     *
     * @param array<string, mixed> $data Event data
     * @return void
     */
    private function observe(array $data): void
    {
        $observer = new LinkTestOrder($this->testOrders, $this->records);
        $observer->execute(new Observer(['event' => new Event($data)]));
    }

    /**
     * A placed order.
     *
     * @param int|null $quoteId Cart it names
     * @param int|null $orderId Its id
     * @return OrderInterface
     */
    private function order(?int $quoteId, ?int $orderId): OrderInterface
    {
        $order = $this->createMock(OrderInterface::class);
        $order->method('getQuoteId')->willReturn($quoteId);
        $order->method('getEntityId')->willReturn($orderId);

        return $order;
    }
}
