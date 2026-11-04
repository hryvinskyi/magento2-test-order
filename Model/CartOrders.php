<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\TestOrder\Model;

use Hryvinskyi\TestOrder\Api\CartOrdersInterface;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Api\SortOrderBuilder;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\OrderRepositoryInterface;

/**
 * Finds a cart's orders through the order repository, by the cart id on the order.
 */
class CartOrders implements CartOrdersInterface
{
    /**
     * @param OrderRepositoryInterface $orderRepository Finds orders
     * @param SearchCriteriaBuilder $searchCriteriaBuilder Builds the search; starts afresh after each one it builds
     * @param SortOrderBuilder $sortOrderBuilder Builds the sort order; starts afresh after each one it builds
     */
    public function __construct(
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly SearchCriteriaBuilder $searchCriteriaBuilder,
        private readonly SortOrderBuilder $sortOrderBuilder
    ) {
    }

    /**
     * @inheritDoc
     */
    public function ofCart(int $quoteId): array
    {
        if ($quoteId <= 0) {
            return [];
        }

        $oldestFirst = $this->sortOrderBuilder
            ->setField(OrderInterface::ENTITY_ID)
            ->setAscendingDirection()
            ->create();
        $criteria = $this->searchCriteriaBuilder
            ->addFilter(OrderInterface::QUOTE_ID, $quoteId)
            ->addSortOrder($oldestFirst)
            ->create();

        return array_values($this->orderRepository->getList($criteria)->getItems());
    }
}
