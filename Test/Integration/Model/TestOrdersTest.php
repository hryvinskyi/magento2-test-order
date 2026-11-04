<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\TestOrder\Test\Integration\Model;

use Hryvinskyi\TestOrder\Api\TestCartRecordsInterface;
use Hryvinskyi\TestOrder\Api\TestOrdersInterface;
use Hryvinskyi\TestOrder\Model\TestOrders;
use Hryvinskyi\TestOrder\Test\Integration\OrderRows;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\Data\OrderInterfaceFactory;
use Magento\TestFramework\Helper\Bootstrap;
use PHPUnit\Framework\TestCase;

/**
 * The question integrations ask, answered from the real records through the container's wiring: by cart for an
 * order object, saved or not, and by order id for readers that hold only an id.
 *
 * @magentoDbIsolation enabled
 * @magentoAppIsolation enabled
 * @magentoAppArea global
 */
class TestOrdersTest extends TestCase
{
    private const int QUOTE_ID = 987101;

    private const int OTHER_QUOTE_ID = 987102;

    /**
     * The container hands out this module's answer.
     *
     * @return void
     */
    public function testTheContainerHandsOutTheAnswer(): void
    {
        self::assertInstanceOf(TestOrders::class, $this->testOrders());
    }

    /**
     * An order still being placed (no entity id yet) from a recognised cart is a test order; one from another cart
     * and one that names no cart are not.
     *
     * @return void
     */
    public function testAnOrderIsAskedAboutByItsCart(): void
    {
        $this->records()->recognise(self::QUOTE_ID);

        self::assertTrue($this->testOrders()->isTestOrder($this->unsavedOrder(self::QUOTE_ID)));
        self::assertFalse($this->testOrders()->isTestOrder($this->unsavedOrder(self::OTHER_QUOTE_ID)));
        self::assertFalse($this->testOrders()->isTestOrder($this->unsavedOrder(null)));
    }

    /**
     * A saved order is a test order by its id through its cart, before it is linked to the record.
     *
     * @return void
     */
    public function testAnOrderIdIsAnsweredThroughItsCartBeforeItIsLinked(): void
    {
        $this->records()->recognise(self::QUOTE_ID);
        $testOrderId = OrderRows::insert(self::QUOTE_ID);
        $ordinaryOrderId = OrderRows::insert(self::OTHER_QUOTE_ID);

        self::assertTrue($this->testOrders()->isTestOrderId($testOrderId));
        self::assertFalse($this->testOrders()->isTestOrderId($ordinaryOrderId));
        self::assertFalse($this->testOrders()->isTestOrderId(2147483000));
    }

    /**
     * A linked order is a test order by its id even when its row no longer names the cart.
     *
     * @return void
     */
    public function testALinkedOrderIdIsAnsweredFromTheRecord(): void
    {
        $this->records()->recognise(self::QUOTE_ID);
        $orderId = OrderRows::insert(self::OTHER_QUOTE_ID);
        $this->records()->linkOrder(self::QUOTE_ID, $orderId);

        self::assertTrue($this->testOrders()->isTestOrderId($orderId));
    }

    /**
     * An answer kept from earlier in the request does not outlive a recognition: a cart (and an order of it) said
     * not to be a test cart is one as soon as it is recognised.
     *
     * @return void
     */
    public function testRecognitionOverridesAnEarlierAnswerInTheSameRequest(): void
    {
        $orderId = OrderRows::insert(self::QUOTE_ID);
        self::assertFalse($this->testOrders()->isTestOrder($this->unsavedOrder(self::QUOTE_ID)));
        self::assertFalse($this->testOrders()->isTestOrderId($orderId));

        $this->records()->recognise(self::QUOTE_ID);

        self::assertTrue($this->testOrders()->isTestOrder($this->unsavedOrder(self::QUOTE_ID)));
        self::assertTrue($this->testOrders()->isTestOrderId($orderId));
    }

    /**
     * An order object that has not been saved.
     *
     * @param int|null $quoteId Cart it names
     * @return OrderInterface
     */
    private function unsavedOrder(?int $quoteId): OrderInterface
    {
        $factory = Bootstrap::getObjectManager()->get(OrderInterfaceFactory::class);
        self::assertInstanceOf(OrderInterfaceFactory::class, $factory);
        $order = $factory->create();
        if ($quoteId !== null) {
            $order->setQuoteId($quoteId);
        }

        return $order;
    }

    /**
     * @return TestOrdersInterface
     */
    private function testOrders(): TestOrdersInterface
    {
        $testOrders = Bootstrap::getObjectManager()->get(TestOrdersInterface::class);
        self::assertInstanceOf(TestOrdersInterface::class, $testOrders);

        return $testOrders;
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
