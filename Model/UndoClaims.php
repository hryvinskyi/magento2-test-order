<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\TestOrder\Model;

use Hryvinskyi\TestOrder\Api\UndoClaimsInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\DuplicateException;
use Magento\Framework\Stdlib\DateTime\DateTime;

/**
 * Keeps the undo claims in `hryvinskyi_test_order_undo`, one row per undone order under the order id as primary key:
 * the insert that wins the key is the claim. A claim disappears with its order.
 */
class UndoClaims implements UndoClaimsInterface
{
    public const string TABLE_NAME = 'hryvinskyi_test_order_undo';

    /**
     * @param ResourceConnection $resourceConnection Database connection provider
     * @param DateTime $clock Current UTC time
     */
    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly DateTime $clock
    ) {
    }

    /**
     * @inheritDoc
     */
    public function claim(int $orderId): bool
    {
        if ($orderId <= 0) {
            return false;
        }

        try {
            $this->resourceConnection->getConnection()->insert(
                $this->resourceConnection->getTableName(self::TABLE_NAME),
                ['order_id' => $orderId, 'undone_at' => $this->clock->gmtDate('Y-m-d H:i:s')]
            );
        } catch (DuplicateException) {
            return false;
        }

        return true;
    }
}
