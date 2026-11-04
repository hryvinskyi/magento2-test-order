<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\TestOrder\Test\Integration\Model;

use Hryvinskyi\TestOrder\Api\TestCartRecordsInterface;
use Hryvinskyi\TestOrder\Api\TestOrderFilterInterface;
use Hryvinskyi\TestOrder\Model\TestOrderFilter;
use Hryvinskyi\TestOrder\Test\Integration\OrderRows;
use Magento\Framework\App\ResourceConnection;
use Magento\Sales\Model\ResourceModel\Order\Collection as OrderCollection;
use Magento\Sales\Model\ResourceModel\Order\CollectionFactory as OrderCollectionFactory;
use Magento\TestFramework\Helper\Bootstrap;
use PHPUnit\Framework\TestCase;

/**
 * An order collection with the filter applied leaves out orders from recognised carts and orders linked to a record,
 * and keeps ordinary orders, including one that names no cart.
 *
 * @magentoDbIsolation enabled
 * @magentoAppIsolation enabled
 * @magentoAppArea global
 */
class TestOrderFilterTest extends TestCase
{
    private const int TEST_QUOTE_ID = 987501;

    private const int ORDINARY_QUOTE_ID = 987502;

    private const int CARTLESS_QUOTE_ID = 987503;

    private const int LINKED_ORDER_QUOTE_ID = 987504;

    private const int LINKED_RECORD_QUOTE_ID = 987505;

    /**
     * The container hands out this module's filter.
     *
     * @return void
     */
    public function testTheContainerHandsOutTheFilter(): void
    {
        self::assertInstanceOf(TestOrderFilter::class, $this->filter());
    }

    /**
     * Test orders are left out, ordinary ones stay; applying the filter twice changes nothing.
     *
     * @return void
     */
    public function testTestOrdersAreLeftOutOfTheCollection(): void
    {
        $this->records()->recognise(self::TEST_QUOTE_ID);
        $testOrderId = OrderRows::insert(self::TEST_QUOTE_ID);
        $ordinaryOrderId = OrderRows::insert(self::ORDINARY_QUOTE_ID);
        $cartlessOrderId = OrderRows::insert(self::CARTLESS_QUOTE_ID);
        $this->forgetCart($cartlessOrderId);
        $this->records()->recognise(self::LINKED_RECORD_QUOTE_ID);
        $linkedOrderId = OrderRows::insert(self::LINKED_ORDER_QUOTE_ID);
        $this->records()->linkOrder(self::LINKED_RECORD_QUOTE_ID, $linkedOrderId);

        $orders = $this->orders([$testOrderId, $ordinaryOrderId, $cartlessOrderId, $linkedOrderId]);
        $this->filter()->excludeTestOrders($orders);
        $this->filter()->excludeTestOrders($orders);

        $kept = array_map(static fn (mixed $id): int => is_numeric($id) ? (int)$id : 0, $orders->getAllIds());
        sort($kept);
        self::assertSame([$ordinaryOrderId, $cartlessOrderId], $kept);
        self::assertSame(2, $orders->getSize());
    }

    /**
     * A collection of the orders with these ids.
     *
     * @param list<int> $orderIds Order entity ids
     * @return OrderCollection
     */
    private function orders(array $orderIds): OrderCollection
    {
        $factory = Bootstrap::getObjectManager()->get(OrderCollectionFactory::class);
        self::assertInstanceOf(OrderCollectionFactory::class, $factory);
        $orders = $factory->create();
        $orders->addFieldToFilter('entity_id', ['in' => $orderIds]);

        return $orders;
    }

    /**
     * Make an order row name no cart.
     *
     * @param int $orderId Order entity id
     * @return void
     */
    private function forgetCart(int $orderId): void
    {
        $resource = Bootstrap::getObjectManager()->get(ResourceConnection::class);
        self::assertInstanceOf(ResourceConnection::class, $resource);
        $resource->getConnection()->update(
            $resource->getTableName('sales_order'),
            ['quote_id' => null],
            ['entity_id = ?' => $orderId]
        );
    }

    /**
     * @return TestOrderFilterInterface
     */
    private function filter(): TestOrderFilterInterface
    {
        $filter = Bootstrap::getObjectManager()->get(TestOrderFilterInterface::class);
        self::assertInstanceOf(TestOrderFilterInterface::class, $filter);

        return $filter;
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
