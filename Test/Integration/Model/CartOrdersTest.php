<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\TestOrder\Test\Integration\Model;

use Hryvinskyi\TestOrder\Api\CartOrdersInterface;
use Hryvinskyi\TestOrder\Model\CartOrders;
use Hryvinskyi\TestOrder\Test\Integration\OrderRows;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\TestFramework\Helper\Bootstrap;
use PHPUnit\Framework\TestCase;

/**
 * A cart's orders are found through the cart id they carry, oldest first, and no other cart's order is among them.
 *
 * @magentoDbIsolation enabled
 * @magentoAppArea global
 */
class CartOrdersTest extends TestCase
{
    private const int QUOTE_ID = 987201;

    /**
     * Every order of the cart, oldest first; another cart's order is left out.
     *
     * @return void
     */
    public function testEveryOrderOfTheCartOldestFirst(): void
    {
        $first = OrderRows::insert(self::QUOTE_ID, '-a');
        $second = OrderRows::insert(self::QUOTE_ID, '-b');
        OrderRows::insert(self::QUOTE_ID + 1);

        $found = array_map(
            static fn (OrderInterface $order): int => (int)$order->getEntityId(),
            $this->cartOrders()->ofCart(self::QUOTE_ID)
        );

        self::assertSame([$first, $second], $found);
    }

    /**
     * A cart from which nothing was placed has no orders.
     *
     * @return void
     */
    public function testACartWithoutOrdersHasNone(): void
    {
        self::assertSame([], $this->cartOrders()->ofCart(self::QUOTE_ID + 2));
        self::assertSame([], $this->cartOrders()->ofCart(0));
    }

    /**
     * The container's cart orders.
     *
     * @return CartOrdersInterface
     */
    private function cartOrders(): CartOrdersInterface
    {
        $cartOrders = Bootstrap::getObjectManager()->get(CartOrdersInterface::class);
        self::assertInstanceOf(CartOrders::class, $cartOrders);

        return $cartOrders;
    }
}
