<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\TestOrder\Test\Integration\Observer;

use Hryvinskyi\TestOrder\Api\TestCartRecordsInterface;
use Hryvinskyi\TestOrder\Model\UndoRunner;
use Hryvinskyi\TestOrder\Observer\UndoTestOrderOnCancel;
use Hryvinskyi\TestOrder\Test\Integration\Fixture\RecordingUndoStep;
use Hryvinskyi\TestOrder\Test\Integration\OrderRows;
use Magento\Framework\Event;
use Magento\Framework\Event\ConfigInterface as EventConfig;
use Magento\Framework\Event\InvokerInterface;
use Magento\Framework\Event\Observer;
use Magento\Sales\Model\Order;
use Magento\TestFramework\Helper\Bootstrap;
use Magento\TestFramework\ObjectManager;
use PHPUnit\Framework\TestCase;

/**
 * The cancellation of an order reaches the undo runner through the shop's own event configuration and observer
 * invoker, in every area an order is cancelled from.
 *
 * Only this module's observer is invoked, never the whole event: the other observers of a cancellation belong to
 * real integrations. The runner gets a recording step in place of the real ones.
 *
 * @magentoDbIsolation enabled
 * @magentoAppIsolation enabled
 */
class UndoTestOrderOnCancelTest extends TestCase
{
    private const string EVENT = 'order_cancel_after';

    private const string OBSERVER = 'hryvinskyi_test_order_undo_test_order_on_cancel';

    private const int QUOTE_ID = 987201;

    /**
     * @var RecordingUndoStep
     */
    private RecordingUndoStep $step;

    /**
     * @inheritDoc
     */
    protected function setUp(): void
    {
        $this->step = new RecordingUndoStep();
        $objectManager = $this->objectManager();
        $runner = $objectManager->create(UndoRunner::class, ['steps' => ['recording' => $this->step]]);
        self::assertInstanceOf(UndoRunner::class, $runner);
        $objectManager->addSharedInstance($runner, UndoRunner::class);
    }

    /**
     * @inheritDoc
     */
    protected function tearDown(): void
    {
        $this->objectManager()->removeSharedInstance(UndoRunner::class);
    }

    /**
     * Cancelling a test order through the REST runner undoes it once.
     *
     * @magentoAppArea webapi_rest
     * @return void
     */
    public function testCancellingATestOrderOverRestUndoesItOnce(): void
    {
        $this->assertCancellingATestOrderUndoesItOnce();
    }

    /**
     * Cancelling a test order from a scheduled job undoes it once.
     *
     * @magentoAppArea crontab
     * @return void
     */
    public function testCancellingATestOrderFromAJobUndoesItOnce(): void
    {
        $this->assertCancellingATestOrderUndoesItOnce();
    }

    /**
     * Cancelling a test order in the admin undoes it once.
     *
     * @magentoAppArea adminhtml
     * @return void
     */
    public function testCancellingATestOrderInTheAdminUndoesItOnce(): void
    {
        $this->assertCancellingATestOrderUndoesItOnce();
    }

    /**
     * Cancelling an ordinary order undoes nothing.
     *
     * @magentoAppArea global
     * @return void
     */
    public function testCancellingAnOrdinaryOrderUndoesNothing(): void
    {
        $this->cancel($this->order(self::QUOTE_ID + 1));

        self::assertSame([], $this->step->undone);
    }

    /**
     * Cancel a test order twice in the area being served, and assert it was undone once.
     *
     * @return void
     */
    private function assertCancellingATestOrderUndoesItOnce(): void
    {
        $this->records()->recognise(self::QUOTE_ID);
        $order = $this->order(self::QUOTE_ID);

        $this->cancel($order);
        $this->cancel($order);

        self::assertSame(['100987201'], $this->step->undone);
    }

    /**
     * Two orders of one test cart are each undone when cancelled: undoing one does not use up the other.
     *
     * @magentoAppArea global
     * @return void
     */
    public function testEachCancelledOrderOfATestCartIsUndone(): void
    {
        $this->records()->recognise(self::QUOTE_ID);

        $this->cancel($this->order(self::QUOTE_ID, '-a'));
        $this->cancel($this->order(self::QUOTE_ID, '-b'));

        self::assertSame(['100987201-a', '100987201-b'], $this->step->undone);
    }

    /**
     * Invoke this module's observer of the cancellation exactly as the event manager would.
     *
     * @param Order $order Order being cancelled
     * @return void
     */
    private function cancel(Order $order): void
    {
        $eventConfig = $this->objectManager()->get(EventConfig::class);
        self::assertInstanceOf(EventConfig::class, $eventConfig);
        $observers = $eventConfig->getObservers(self::EVENT);
        self::assertArrayHasKey(self::OBSERVER, $observers);
        $configuration = $observers[self::OBSERVER];
        self::assertIsArray($configuration);
        $instance = $configuration['instance'] ?? null;
        self::assertIsString($instance);
        self::assertSame(UndoTestOrderOnCancel::class, ltrim($instance, '\\'));

        $invoker = $this->objectManager()->get(InvokerInterface::class);
        self::assertInstanceOf(InvokerInterface::class, $invoker);
        $event = new Event(['name' => self::EVENT, 'order' => $order]);
        $invoker->dispatch($configuration, new Observer(['event' => $event]));
    }

    /**
     * A saved order placed from the cart, as the cancellation hands it over.
     *
     * @param int $quoteId Cart id
     * @param string $suffix Tells apart several orders of one cart
     * @return Order
     */
    private function order(int $quoteId, string $suffix = ''): Order
    {
        $order = $this->objectManager()->create(Order::class);
        self::assertInstanceOf(Order::class, $order);
        $order->setId(OrderRows::insert($quoteId, $suffix));
        $order->setQuoteId($quoteId);
        $order->setIncrementId('100' . $quoteId . $suffix);

        return $order;
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

    /**
     * The test framework's object manager.
     *
     * @return ObjectManager
     */
    private function objectManager(): ObjectManager
    {
        $objectManager = Bootstrap::getObjectManager();
        self::assertInstanceOf(ObjectManager::class, $objectManager);

        return $objectManager;
    }
}
