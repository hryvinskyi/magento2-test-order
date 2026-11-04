<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\TestOrder\Test\Integration\Model;

use Hryvinskyi\TestOrder\Api\UndoClaimsInterface;
use Hryvinskyi\TestOrder\Model\UndoClaims;
use Hryvinskyi\TestOrder\Test\Integration\OrderRows;
use Magento\Framework\App\ResourceConnection;
use Magento\TestFramework\Helper\Bootstrap;
use PHPUnit\Framework\TestCase;

/**
 * The undo claims against the real table: each order is claimed once, two orders of one cart are claimed each on
 * their own, and a claim goes with its order.
 *
 * @magentoDbIsolation enabled
 * @magentoAppArea global
 */
class UndoClaimsTest extends TestCase
{
    private const int QUOTE_ID = 987301;

    /**
     * The first claim on an order wins; every later one loses.
     *
     * @return void
     */
    public function testAnOrderIsClaimedOnce(): void
    {
        $orderId = OrderRows::insert(self::QUOTE_ID);

        self::assertTrue($this->undoClaims()->claim($orderId));
        self::assertFalse($this->undoClaims()->claim($orderId));
        self::assertSame(1, $this->claimCount($orderId));
    }

    /**
     * Claiming one order of a cart leaves the cart's other order to be claimed on its own.
     *
     * @return void
     */
    public function testEachOrderOfACartIsClaimedOnItsOwn(): void
    {
        $first = OrderRows::insert(self::QUOTE_ID, '-a');
        $second = OrderRows::insert(self::QUOTE_ID, '-b');

        self::assertTrue($this->undoClaims()->claim($first));
        self::assertTrue($this->undoClaims()->claim($second));
        self::assertFalse($this->undoClaims()->claim($first));
    }

    /**
     * A claim disappears with its order.
     *
     * @return void
     */
    public function testAClaimGoesWithItsOrder(): void
    {
        $orderId = OrderRows::insert(self::QUOTE_ID);
        $this->undoClaims()->claim($orderId);

        OrderRows::delete($orderId);

        self::assertSame(0, $this->claimCount($orderId));
    }

    /**
     * No id names no order, and nothing is claimed for it.
     *
     * @return void
     */
    public function testANonPositiveIdIsNotClaimed(): void
    {
        self::assertFalse($this->undoClaims()->claim(0));
    }

    /**
     * The container's undo claims.
     *
     * @return UndoClaimsInterface
     */
    private function undoClaims(): UndoClaimsInterface
    {
        $claims = Bootstrap::getObjectManager()->get(UndoClaimsInterface::class);
        self::assertInstanceOf(UndoClaims::class, $claims);

        return $claims;
    }

    /**
     * How many claims the table holds for an order.
     *
     * @param int $orderId Order entity id
     * @return int
     */
    private function claimCount(int $orderId): int
    {
        $resource = Bootstrap::getObjectManager()->get(ResourceConnection::class);
        self::assertInstanceOf(ResourceConnection::class, $resource);
        $connection = $resource->getConnection();
        $count = $connection->fetchOne(
            $connection->select()
                ->from($resource->getTableName(UndoClaims::TABLE_NAME), ['COUNT(*)'])
                ->where('order_id = ?', $orderId)
        );

        return is_numeric($count) ? (int)$count : -1;
    }
}
