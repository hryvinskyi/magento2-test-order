<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\TestOrder\Model;

use Hryvinskyi\TestOrder\Api\Data\TestOrderInterface;
use Hryvinskyi\TestOrder\Api\TestOrderFilterInterface;
use Magento\Sales\Model\ResourceModel\Order\Collection as OrderCollection;

/**
 * Leaves out of an order collection every order whose cart was recognised, or that was linked to a record.
 *
 * Written as `NOT EXISTS`, never `NOT IN`: an order that names no cart has a null cart id, and `NULL NOT IN (…)` would
 * drop it too.
 */
class TestOrderFilter implements TestOrderFilterInterface
{
    /**
     * Collection flag marking a collection the condition was already added to.
     */
    private const string APPLIED_FLAG = 'hryvinskyi_test_orders_excluded';

    private const string ORDER_ALIAS = 'main_table';

    private const string RECORD_ALIAS = 'test_order';

    /**
     * @inheritDoc
     */
    public function excludeTestOrders(OrderCollection $orders): void
    {
        if ($orders->getFlag(self::APPLIED_FLAG)) {
            return;
        }

        $record = $orders->getConnection()->select()
            ->from(
                [self::RECORD_ALIAS => $orders->getTable(TestOrderRepository::TABLE_NAME)],
                [TestOrderInterface::ENTITY_ID]
            )
            ->where(sprintf(
                '%1$s.%2$s = %3$s.quote_id OR %1$s.%4$s = %3$s.entity_id',
                self::RECORD_ALIAS,
                TestOrderInterface::QUOTE_ID,
                self::ORDER_ALIAS,
                TestOrderInterface::ORDER_ID
            ));
        $orders->getSelect()->where('NOT EXISTS (' . $record->assemble() . ')');
        $orders->setFlag(self::APPLIED_FLAG, true);
    }
}
