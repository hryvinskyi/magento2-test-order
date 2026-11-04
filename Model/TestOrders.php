<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\TestOrder\Model;

use Hryvinskyi\TestOrder\Api\KeptTestOrderAnswersInterface;
use Hryvinskyi\TestOrder\Api\TestCartsInterface;
use Hryvinskyi\TestOrder\Api\TestOrdersInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Sales\Api\Data\OrderInterface;

/**
 * Answers whether an order is a test order from the test-cart records, once per cart or order and request.
 *
 * An order object is answered by its cart. An order id is looked up: a lookup either finds a record's id or finds
 * nothing (the driver's `false`), so "is a test order" is "an id came back".
 */
class TestOrders implements TestOrdersInterface
{
    private const string ORDER_TABLE = 'sales_order';

    /**
     * @param ResourceConnection $resourceConnection Database connection provider
     * @param KeptTestOrderAnswersInterface $answers The request's kept answers
     * @param TestCartsInterface $testCarts Answers whether the cart an order was placed from is a test cart
     */
    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly KeptTestOrderAnswersInterface $answers,
        private readonly TestCartsInterface $testCarts
    ) {
    }

    /**
     * @inheritDoc
     */
    public function isTestOrder(OrderInterface $order): bool
    {
        return $this->testCarts->isTestCart((int)$order->getQuoteId());
    }

    /**
     * @inheritDoc
     */
    public function isTestOrderId(int $orderId): bool
    {
        if ($orderId <= 0) {
            return false;
        }

        $known = $this->answers->forOrder($orderId);
        if ($known !== null) {
            return $known;
        }

        $connection = $this->resourceConnection->getConnection();
        $quoteOfOrder = $connection->select()
            ->from($this->table(self::ORDER_TABLE), ['quote_id'])
            ->where('entity_id = ?', $orderId);
        $isTestOrder = is_numeric($connection->fetchOne(
            $connection->select()
                ->from($this->table(TestOrderRepository::TABLE_NAME), ['entity_id'])
                ->where('order_id = ?', $orderId)
                ->orWhere('quote_id IN (?)', $quoteOfOrder)
                ->limit(1)
        ));
        $this->answers->rememberOrder($orderId, $isTestOrder);

        return $isTestOrder;
    }

    /**
     * A table's name with the installation's prefix.
     *
     * @param string $name Table name
     * @return string
     */
    private function table(string $name): string
    {
        return $this->resourceConnection->getTableName($name);
    }
}
