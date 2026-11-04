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
 * Bare `sales_order` rows for tests that only need an order id and its cart id.
 *
 * Written straight to the table, so that no observer of an order save runs: saving a real order would set every
 * integration in the installation in motion. Every other required column gets its type's empty value, whichever
 * columns the installed modules have added.
 */
class OrderRows
{
    private const array NUMERIC_TYPES = ['int', 'smallint', 'tinyint', 'mediumint', 'bigint', 'decimal'];

    /**
     * Insert an order row placed from the cart.
     *
     * @param int $quoteId Cart (quote) id the order names
     * @param string $suffix Tells apart several orders of one cart, whose numbers must differ
     * @param string $state Order state and status; empty for neither
     * @return int The order's entity id
     */
    public static function insert(int $quoteId, string $suffix = '', string $state = ''): int
    {
        $resource = self::resource();
        $connection = $resource->getConnection();
        $table = $resource->getTableName('sales_order');

        $row = [];
        foreach ($connection->describeTable($table) as $name => $column) {
            if (!is_array($column) || !self::needsAValue($column)) {
                continue;
            }
            $row[(string)$name] = in_array($column['DATA_TYPE'] ?? null, self::NUMERIC_TYPES, true) ? 0 : '';
        }
        $row['quote_id'] = $quoteId;
        $row['increment_id'] = 'TESTORDER-' . $quoteId . $suffix;
        if ($state !== '') {
            $row['state'] = $state;
            $row['status'] = $state;
        }
        $connection->insert($table, $row);

        $orderId = $connection->fetchOne(
            $connection->select()->from($table, ['entity_id'])->where('increment_id = ?', $row['increment_id'])
        );

        return is_numeric($orderId) ? (int)$orderId : 0;
    }

    /**
     * Delete an order row.
     *
     * @param int $orderId Order entity id
     * @return void
     */
    public static function delete(int $orderId): void
    {
        $resource = self::resource();
        $resource->getConnection()->delete($resource->getTableName('sales_order'), ['entity_id = ?' => $orderId]);
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
