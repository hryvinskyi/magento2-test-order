<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\TestOrder\Test\Integration;

use Magento\Framework\App\ResourceConnection;
use Magento\TestFramework\Helper\Bootstrap;

/**
 * Bare cart (`quote`) rows, with or without a masked id, for tests that only need a cart to exist.
 *
 * Written straight to the tables, so that no observer of a cart save runs. A closed cart (`is_active = 0`) is what a
 * cart becomes once its order is placed. An open one has no items, so placement reaches the core and the core refuses
 * it with its own message: no order is ever created from either.
 */
class CartRows
{
    private const array NUMERIC_TYPES = ['int', 'smallint', 'tinyint', 'mediumint', 'bigint', 'decimal'];

    private const int STORE_ID = 1;

    /**
     * Insert a closed guest cart.
     *
     * @param string|null $maskedId Masked id the cart is known by to guests; null for a cart that has none
     * @return int The cart's entity id
     */
    public static function insertClosedGuestCart(?string $maskedId): int
    {
        return self::insertGuestCart($maskedId, 0);
    }

    /**
     * Insert an open guest cart without items.
     *
     * @param string|null $maskedId Masked id the cart is known by to guests; null for a cart that has none
     * @return int The cart's entity id
     */
    public static function insertOpenGuestCart(?string $maskedId): int
    {
        return self::insertGuestCart($maskedId, 1);
    }

    /**
     * Insert a guest cart.
     *
     * @param string|null $maskedId Masked id the cart is known by to guests; null for a cart that has none
     * @param int $isActive The stored flag: 1 for an open cart, 0 for a closed one
     * @return int The cart's entity id
     */
    private static function insertGuestCart(?string $maskedId, int $isActive): int
    {
        $resource = self::resource();
        $connection = $resource->getConnection();
        $quoteTable = $resource->getTableName('quote');

        $row = [];
        foreach ($connection->describeTable($quoteTable) as $name => $column) {
            if (!is_array($column) || !self::needsAValue($column)) {
                continue;
            }
            $row[(string)$name] = in_array($column['DATA_TYPE'] ?? null, self::NUMERIC_TYPES, true) ? 0 : '';
        }
        $row['store_id'] = self::STORE_ID;
        $row['is_active'] = $isActive;
        $row['customer_id'] = null;
        if (!$connection instanceof \Zend_Db_Adapter_Abstract) {
            throw new \LogicException('The database connection cannot tell the id of a row it inserted.');
        }
        $connection->insert($quoteTable, $row);
        $quoteId = (int)$connection->lastInsertId($quoteTable);

        if ($maskedId !== null) {
            $connection->insert(
                $resource->getTableName('quote_id_mask'),
                ['quote_id' => $quoteId, 'masked_id' => $maskedId]
            );
        }

        return $quoteId;
    }

    /**
     * Whether an insert must name the column: required, without a default and not generated.
     *
     * @param array<mixed> $column Column as the adapter describes it
     * @return bool
     */
    private static function needsAValue(array $column): bool
    {
        return ($column['NULLABLE'] ?? true) === false
            && ($column['DEFAULT'] ?? null) === null
            && ($column['IDENTITY'] ?? false) === false;
    }

    /**
     * The installation's database resource.
     *
     * @return ResourceConnection
     */
    private static function resource(): ResourceConnection
    {
        $resource = Bootstrap::getObjectManager()->get(ResourceConnection::class);
        if (!$resource instanceof ResourceConnection) {
            throw new \LogicException('The container has no database resource.');
        }

        return $resource;
    }
}
