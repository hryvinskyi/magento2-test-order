<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\TestOrder\Model\Data;

use Hryvinskyi\TestOrder\Api\Data\EventOrdersInterface;
use Magento\Sales\Api\Data\OrderInterface;

/**
 * The orders one event is about. Immutable, and valid by construction: only order objects and positive order and cart
 * ids.
 *
 * Created through the generated `EventOrdersInterfaceFactory`.
 */
class EventOrders implements EventOrdersInterface
{
    /**
     * @var list<OrderInterface>
     */
    private readonly array $orders;

    /**
     * @var list<int>
     */
    private readonly array $orderIds;

    /**
     * @var list<int>
     */
    private readonly array $quoteIds;

    /**
     * @param array<mixed> $orders Order objects
     * @param array<mixed> $orderIds Positive order entity ids
     * @param array<mixed> $quoteIds Positive cart (quote) entity ids
     * @throws \InvalidArgumentException When an order is not an order object or an id is not a positive integer
     */
    public function __construct(array $orders = [], array $orderIds = [], array $quoteIds = [])
    {
        $this->orders = array_map(
            static function (mixed $order): OrderInterface {
                if (!$order instanceof OrderInterface) {
                    throw new \InvalidArgumentException('The orders an event is about are order objects.');
                }

                return $order;
            },
            array_values($orders)
        );
        $this->orderIds = array_map(
            static function (mixed $orderId): int {
                if (!is_int($orderId) || $orderId <= 0) {
                    throw new \InvalidArgumentException('An order an event names is named by a positive id.');
                }

                return $orderId;
            },
            array_values($orderIds)
        );
        $this->quoteIds = array_map(
            static function (mixed $quoteId): int {
                if (!is_int($quoteId) || $quoteId <= 0) {
                    throw new \InvalidArgumentException('A cart an event carries is named by a positive id.');
                }

                return $quoteId;
            },
            array_values($quoteIds)
        );
    }

    /**
     * @inheritDoc
     */
    public function getOrders(): array
    {
        return $this->orders;
    }

    /**
     * @inheritDoc
     */
    public function getOrderIds(): array
    {
        return $this->orderIds;
    }

    /**
     * @inheritDoc
     */
    public function getQuoteIds(): array
    {
        return $this->quoteIds;
    }

    /**
     * @inheritDoc
     */
    public function isEmpty(): bool
    {
        return $this->orders === [] && $this->orderIds === [] && $this->quoteIds === [];
    }
}
