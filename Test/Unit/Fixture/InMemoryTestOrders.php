<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\TestOrder\Test\Unit\Fixture;

use Hryvinskyi\TestOrder\Api\TestCartsInterface;
use Hryvinskyi\TestOrder\Api\TestOrdersInterface;
use Magento\Sales\Api\Data\OrderInterface;

/**
 * Answers whether an order is a test order, or a cart a test cart, from fixed lists of recognised carts and
 * test-order ids, and counts the questions asked.
 */
class InMemoryTestOrders implements TestOrdersInterface, TestCartsInterface
{
    /**
     * @var int
     */
    public int $questions = 0;

    /**
     * @param list<int> $testQuoteIds Recognised carts
     * @param list<int> $testOrderIds Ids of test orders
     */
    public function __construct(
        private readonly array $testQuoteIds = [],
        private readonly array $testOrderIds = []
    ) {
    }

    /**
     * @inheritDoc
     */
    public function isTestOrder(OrderInterface $order): bool
    {
        $this->questions++;

        return in_array((int)$order->getQuoteId(), $this->testQuoteIds, true);
    }

    /**
     * @inheritDoc
     */
    public function isTestOrderId(int $orderId): bool
    {
        $this->questions++;

        return in_array($orderId, $this->testOrderIds, true);
    }

    /**
     * @inheritDoc
     */
    public function isTestCart(int $quoteId): bool
    {
        $this->questions++;

        return in_array($quoteId, $this->testQuoteIds, true);
    }
}
