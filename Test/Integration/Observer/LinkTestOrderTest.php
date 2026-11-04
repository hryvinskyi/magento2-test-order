<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\TestOrder\Test\Integration\Observer;

use Hryvinskyi\TestOrder\Api\TestCartRecordsInterface;
use Hryvinskyi\TestOrder\Api\TestOrderRepositoryInterface;
use Hryvinskyi\TestOrder\Observer\LinkTestOrder;
use Hryvinskyi\TestOrder\Test\Integration\OrderRows;
use Magento\Framework\Event;
use Magento\Framework\Event\ConfigInterface as EventConfig;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\TestFramework\Helper\Bootstrap;
use PHPUnit\Framework\TestCase;

/**
 * The observer the event configuration names for a successful placement links a test order to its cart's record in
 * the real table, and writes nothing for an ordinary order. Only this observer is run: the event's other observers
 * belong to integrations a test must not call.
 *
 * @magentoDbIsolation enabled
 * @magentoAppIsolation enabled
 * @magentoAppArea global
 */
class LinkTestOrderTest extends TestCase
{
    private const string EVENT = 'sales_model_service_quote_submit_success';

    private const int TEST_QUOTE_ID = 987401;

    private const int ORDINARY_QUOTE_ID = 987402;

    /**
     * A placed test order is linked to its cart's record.
     *
     * @return void
     */
    public function testAPlacedTestOrderIsLinked(): void
    {
        $this->records()->recognise(self::TEST_QUOTE_ID);
        $orderId = OrderRows::insert(self::TEST_QUOTE_ID);

        $this->observer()->execute($this->placed(self::TEST_QUOTE_ID, $orderId));

        self::assertSame($orderId, $this->repository()->getByQuoteId(self::TEST_QUOTE_ID)->getOrderId());
    }

    /**
     * A placed ordinary order gets no record.
     *
     * @return void
     */
    public function testAPlacedOrdinaryOrderIsNotRecorded(): void
    {
        $orderId = OrderRows::insert(self::ORDINARY_QUOTE_ID);

        $this->observer()->execute($this->placed(self::ORDINARY_QUOTE_ID, $orderId));

        $this->expectException(NoSuchEntityException::class);
        $this->repository()->getByQuoteId(self::ORDINARY_QUOTE_ID);
    }

    /**
     * The observer the event configuration names for a successful placement, as the container builds it.
     *
     * @return ObserverInterface
     */
    private function observer(): ObserverInterface
    {
        $eventConfig = Bootstrap::getObjectManager()->get(EventConfig::class);
        self::assertInstanceOf(EventConfig::class, $eventConfig);
        $configured = array_filter(
            $eventConfig->getObservers(self::EVENT),
            static fn (mixed $entry): bool => is_array($entry) && ($entry['instance'] ?? null) === LinkTestOrder::class
        );
        self::assertCount(1, $configured, 'The link observer is not configured for a successful placement.');

        $observer = Bootstrap::getObjectManager()->get(LinkTestOrder::class);
        self::assertInstanceOf(ObserverInterface::class, $observer);

        return $observer;
    }

    /**
     * The event of a successful placement of an order.
     *
     * @param int $quoteId Cart the order was placed from
     * @param int $orderId The order's id
     * @return Observer
     */
    private function placed(int $quoteId, int $orderId): Observer
    {
        $order = Bootstrap::getObjectManager()->create(OrderInterface::class);
        self::assertInstanceOf(OrderInterface::class, $order);
        $order->setEntityId($orderId);
        $order->setQuoteId($quoteId);
        $event = new Event(['order' => $order]);
        $event->setName(self::EVENT);

        return new Observer(['event' => $event, 'order' => $order]);
    }

    /**
     * @return TestOrderRepositoryInterface
     */
    private function repository(): TestOrderRepositoryInterface
    {
        $repository = Bootstrap::getObjectManager()->get(TestOrderRepositoryInterface::class);
        self::assertInstanceOf(TestOrderRepositoryInterface::class, $repository);

        return $repository;
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
