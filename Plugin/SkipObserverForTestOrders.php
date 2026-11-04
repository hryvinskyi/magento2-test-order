<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\TestOrder\Plugin;

use Hryvinskyi\TestOrder\Api\Data\EventOrdersInterface;
use Hryvinskyi\TestOrder\Api\OrderEventReaderInterface;
use Hryvinskyi\TestOrder\Api\TestCartsInterface;
use Hryvinskyi\TestOrder\Api\TestOrdersInterface;
use Magento\Framework\Event;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Psr\Log\LoggerInterface;

/**
 * Keeps an observer from acting on a test order.
 *
 * An integration whose observer must never act on a test order puts this plugin on its own observer class in its own
 * `di.xml`; nothing here names the observers it guards. The orders an event is about are found by the pool of event
 * readers. When any of them is a test order, or the cart of one, the observer does not run; otherwise it runs as
 * usual.
 *
 * An event in which no reader finds an order still runs the observer, as for an ordinary order, and is logged as a
 * warning naming the observer and the event: an opted-out observer receiving an event nobody can read is a wiring
 * mistake to fix, never something to pass over in silence.
 */
class SkipObserverForTestOrders
{
    private const string INTERCEPTOR_SUFFIX = '\Interceptor';

    /**
     * @var list<OrderEventReaderInterface>
     */
    private readonly array $readers;

    /**
     * @param TestOrdersInterface $testOrders Answers whether an order is a test order
     * @param TestCartsInterface $testCarts Answers whether a cart is a test cart
     * @param LoggerInterface $logger Where an unreadable event is reported
     * @param array<mixed> $readers Readers of the orders an event is about, by name
     * @throws \InvalidArgumentException When an item of the pool is not an event reader
     */
    public function __construct(
        private readonly TestOrdersInterface $testOrders,
        private readonly TestCartsInterface $testCarts,
        private readonly LoggerInterface $logger,
        array $readers = []
    ) {
        $checked = [];
        foreach ($readers as $name => $reader) {
            if (!$reader instanceof OrderEventReaderInterface) {
                throw new \InvalidArgumentException(sprintf(
                    'The test-order event reader "%s" does not implement %s.',
                    $name,
                    OrderEventReaderInterface::class
                ));
            }
            $checked[] = $reader;
        }
        $this->readers = $checked;
    }

    /**
     * Run the observer unless the event is about a test order.
     *
     * @param ObserverInterface $subject Observer being guarded
     * @param callable $proceed The observer's own execution
     * @param Observer $observer The event being observed
     * @return void
     */
    public function aroundExecute(ObserverInterface $subject, callable $proceed, Observer $observer): void
    {
        $event = $observer->getEvent();
        $foundAnOrder = false;
        foreach ($this->readers as $reader) {
            $found = $reader->read($event);
            if ($this->includesATestOrder($found)) {
                return;
            }
            $foundAnOrder = $foundAnOrder || !$found->isEmpty();
        }

        if (!$foundAnOrder) {
            $this->reportUnreadableEvent($subject, $event);
        }

        $proceed($observer);
    }

    /**
     * Whether any order found is a test order, or any cart found is the cart of one.
     *
     * @param EventOrdersInterface $found Orders a reader found
     * @return bool
     */
    private function includesATestOrder(EventOrdersInterface $found): bool
    {
        foreach ($found->getOrders() as $order) {
            if ($this->testOrders->isTestOrder($order)) {
                return true;
            }
        }

        foreach ($found->getOrderIds() as $orderId) {
            if ($this->testOrders->isTestOrderId($orderId)) {
                return true;
            }
        }

        foreach ($found->getQuoteIds() as $quoteId) {
            if ($this->testCarts->isTestCart($quoteId)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Report a guarded observer that received an event no reader finds an order in.
     *
     * @param ObserverInterface $subject Observer being guarded
     * @param Event $event The event it received
     * @return void
     */
    private function reportUnreadableEvent(ObserverInterface $subject, Event $event): void
    {
        $observerClass = $subject::class;
        if (str_ends_with($observerClass, self::INTERCEPTOR_SUFFIX)) {
            $observerClass = substr($observerClass, 0, -strlen(self::INTERCEPTOR_SUFFIX));
        }

        $this->logger->warning(
            'An observer that must not act on test orders received an event in which no order could be found, '
            . 'so it ran as for an ordinary order. Contribute an event reader for this event.',
            ['observer' => $observerClass, 'event' => $event->getName()]
        );
    }
}
