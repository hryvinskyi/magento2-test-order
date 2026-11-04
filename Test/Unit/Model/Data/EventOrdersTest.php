<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\TestOrder\Test\Unit\Model\Data;

use Hryvinskyi\TestOrder\Model\Data\EventOrders;
use Magento\Framework\DataObject;
use Magento\Sales\Api\Data\OrderInterface;
use PHPUnit\Framework\TestCase;

/**
 * The orders an event is about hold only order objects and positive order and cart ids, and know when they hold
 * nothing.
 */
class EventOrdersTest extends TestCase
{
    /**
     * Orders and ids are kept as given, in order, as lists.
     *
     * @return void
     */
    public function testOrdersAndIdsAreKept(): void
    {
        $order = $this->createMock(OrderInterface::class);

        $found = new EventOrders(['a' => $order], [3 => 7, 9 => 8], [5 => 11]);

        self::assertSame([$order], $found->getOrders());
        self::assertSame([7, 8], $found->getOrderIds());
        self::assertSame([11], $found->getQuoteIds());
        self::assertFalse($found->isEmpty());
    }

    /**
     * Nothing found is empty; an order id alone, a cart id alone or an order alone is not.
     *
     * @return void
     */
    public function testEmptinessIsHavingNeitherOrdersNorIds(): void
    {
        self::assertTrue((new EventOrders())->isEmpty());
        self::assertFalse((new EventOrders([], [1]))->isEmpty());
        self::assertFalse((new EventOrders([], [], [1]))->isEmpty());
        self::assertFalse((new EventOrders([$this->createMock(OrderInterface::class)]))->isEmpty());
    }

    /**
     * Something that is not an order object is refused.
     *
     * @return void
     */
    public function testANonOrderIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new EventOrders([new DataObject()]);
    }

    /**
     * An id that is not a positive integer is refused.
     *
     * @dataProvider invalidIds
     * @param mixed $orderId Id given
     * @return void
     */
    public function testAnIdThatIsNotAPositiveIntegerIsRefused(mixed $orderId): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new EventOrders([], [$orderId]);
    }

    /**
     * A cart id that is not a positive integer is refused.
     *
     * @dataProvider invalidIds
     * @param mixed $quoteId Id given
     * @return void
     */
    public function testACartIdThatIsNotAPositiveIntegerIsRefused(mixed $quoteId): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new EventOrders([], [], [$quoteId]);
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function invalidIds(): array
    {
        return [
            'zero' => [0],
            'negative' => [-3],
            'digit string' => ['5'],
            'null' => [null],
        ];
    }
}
