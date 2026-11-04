<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\TestOrder\Model;

use Hryvinskyi\TestOrder\Api\Data\TestOrderInterface;
use Hryvinskyi\TestOrder\Api\Data\TestOrderInterfaceFactory;
use Hryvinskyi\TestOrder\Api\StaleTestCartsInterface;
use Hryvinskyi\TestOrder\Api\TestCartRecordsInterface;
use Hryvinskyi\TestOrder\Api\TestOrderRecordChangesInterface;
use Hryvinskyi\TestOrder\Api\TestOrderRepositoryInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Select;
use Magento\Framework\DB\Sql\ExpressionFactory;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Magento\Sales\Model\Order;

/**
 * Stores test-cart records in `hryvinskyi_test_order`, and keeps the request's answers in step with what it writes.
 */
class TestOrderRepository implements
    TestOrderRepositoryInterface,
    TestCartRecordsInterface,
    StaleTestCartsInterface
{
    public const string TABLE_NAME = 'hryvinskyi_test_order';

    private const string DATE_FORMAT = 'Y-m-d H:i:s';

    private const string ORDER_TABLE = 'sales_order';

    /**
     * @param ResourceConnection $resourceConnection Database connection provider
     * @param TestOrderInterfaceFactory $testOrderFactory Creates the records read back
     * @param DateTime $clock Current UTC time
     * @param TestOrderRecordChangesInterface $recordChanges Keeps the request's answers in step with each write
     * @param ExpressionFactory $expressionFactory Creates the SQL expression that raises a count in place
     */
    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly TestOrderInterfaceFactory $testOrderFactory,
        private readonly DateTime $clock,
        private readonly TestOrderRecordChangesInterface $recordChanges,
        private readonly ExpressionFactory $expressionFactory
    ) {
    }

    /**
     * @inheritDoc
     */
    public function recognise(int $quoteId): void
    {
        $this->resourceConnection->getConnection()->insertOnDuplicate(
            $this->table(),
            [
                TestOrderInterface::QUOTE_ID => $quoteId,
                TestOrderInterface::RECOGNISED_AT => $this->now(),
            ],
            [TestOrderInterface::RECOGNISED_AT]
        );
        $this->recordChanges->recognised($quoteId);
    }

    /**
     * @inheritDoc
     */
    public function getByQuoteId(int $quoteId): TestOrderInterface
    {
        $connection = $this->resourceConnection->getConnection();
        $row = $connection->fetchRow(
            $connection->select()
                ->from($this->table())
                ->where(TestOrderInterface::QUOTE_ID . ' = ?', $quoteId)
        );
        if (!is_array($row)) {
            throw new NoSuchEntityException(__('No test order was recognised for cart %1.', $quoteId));
        }

        return $this->hydrate($row);
    }

    /**
     * @inheritDoc
     */
    public function linkOrder(int $quoteId, int $orderId): void
    {
        $linked = $this->resourceConnection->getConnection()->update(
            $this->table(),
            [TestOrderInterface::ORDER_ID => $orderId],
            [TestOrderInterface::QUOTE_ID . ' = ?' => $quoteId]
        );
        if ($linked > 0) {
            $this->recordChanges->linked($quoteId, $orderId);
        }
    }

    /**
     * @inheritDoc
     */
    public function forgetOrderless(int $quoteId): bool
    {
        $table = $this->table();
        $forgotten = $this->resourceConnection->getConnection()->delete(
            $table,
            [
                TestOrderInterface::QUOTE_ID . ' = ?' => $quoteId,
                sprintf(
                    'NOT EXISTS (%s)',
                    $this->ordersOfCart($table . '.' . TestOrderInterface::QUOTE_ID)->assemble()
                ),
            ]
        );
        if ($forgotten > 0) {
            $this->recordChanges->forgotten($quoteId);
        }

        return $forgotten > 0;
    }

    /**
     * @inheritDoc
     */
    public function getStaleQuoteIds(int $minutes, int $maxFailedAttempts, int $limit, bool $deletesCancelled): array
    {
        $connection = $this->resourceConnection->getConnection();
        $quoteIdColumn = 'record.' . TestOrderInterface::QUOTE_ID;
        $notUndone = $this->ordersOfCart($quoteIdColumn)
            ->joinLeft(
                ['undo' => $this->resourceConnection->getTableName(UndoClaims::TABLE_NAME)],
                'undo.order_id = placed.entity_id',
                []
            )
            ->where('undo.order_id IS NULL');
        $worthEnding = [sprintf('EXISTS (%s)', $notUndone->assemble())];
        if ($deletesCancelled) {
            $cancelled = $this->ordersOfCart($quoteIdColumn)->where('placed.state = ?', Order::STATE_CANCELED);
            $worthEnding[] = sprintf('EXISTS (%s)', $cancelled->assemble());
        }

        $quoteIds = $connection->fetchCol(
            $connection->select()
                ->from(['record' => $this->table()], [TestOrderInterface::QUOTE_ID])
                ->where('record.' . TestOrderInterface::RECOGNISED_AT . ' <= ?', $this->minutesAgo($minutes))
                ->where('record.' . TestOrderInterface::ABANDON_ATTEMPTS . ' < ?', $maxFailedAttempts)
                ->where(implode(' OR ', $worthEnding))
                ->order('record.' . TestOrderInterface::RECOGNISED_AT . ' ASC')
                ->limit($limit)
        );

        return array_values(array_map($this->intOf(...), $quoteIds));
    }

    /**
     * @inheritDoc
     */
    public function recordFailedAbandon(int $quoteId): int
    {
        $connection = $this->resourceConnection->getConnection();
        $connection->update(
            $this->table(),
            [
                TestOrderInterface::ABANDON_ATTEMPTS => $this->expressionFactory->create(
                    ['expression' => TestOrderInterface::ABANDON_ATTEMPTS . ' + 1']
                ),
            ],
            [TestOrderInterface::QUOTE_ID . ' = ?' => $quoteId]
        );

        return $this->intOf($connection->fetchOne(
            $connection->select()
                ->from($this->table(), [TestOrderInterface::ABANDON_ATTEMPTS])
                ->where(TestOrderInterface::QUOTE_ID . ' = ?', $quoteId)
        ));
    }

    /**
     * @inheritDoc
     */
    public function removeOrderless(int $minutes): int
    {
        $table = $this->table();

        return $this->resourceConnection->getConnection()->delete(
            $table,
            [
                TestOrderInterface::RECOGNISED_AT . ' <= ?' => $this->minutesAgo($minutes),
                TestOrderInterface::ORDER_ID . ' IS NULL',
                sprintf(
                    'NOT EXISTS (%s)',
                    $this->ordersOfCart($table . '.' . TestOrderInterface::QUOTE_ID)->assemble()
                ),
            ]
        );
    }

    /**
     * A sub-select of the orders placed from the cart the outer query names.
     *
     * @param string $quoteIdColumn Qualified column of the outer query that holds the cart id
     * @return Select
     */
    private function ordersOfCart(string $quoteIdColumn): Select
    {
        return $this->resourceConnection->getConnection()->select()
            ->from(['placed' => $this->resourceConnection->getTableName(self::ORDER_TABLE)], ['entity_id'])
            ->where('placed.quote_id = ' . $quoteIdColumn);
    }

    /**
     * The UTC time a number of minutes ago, in the table's format.
     *
     * @param int $minutes Minutes back from now
     * @return string
     */
    private function minutesAgo(int $minutes): string
    {
        return $this->clock->gmtDate(self::DATE_FORMAT, $this->clock->gmtTimestamp() - $minutes * 60);
    }

    /**
     * Turn a stored row into a record.
     *
     * @param array<mixed> $row Row of the table
     * @return TestOrderInterface
     */
    private function hydrate(array $row): TestOrderInterface
    {
        return $this->testOrderFactory->create([
            'entityId' => $this->intOf($row[TestOrderInterface::ENTITY_ID] ?? null),
            'quoteId' => $this->intOf($row[TestOrderInterface::QUOTE_ID] ?? null),
            'recognisedAt' => $this->stringOf($row[TestOrderInterface::RECOGNISED_AT] ?? null),
            'orderId' => $this->nullableIntOf($row[TestOrderInterface::ORDER_ID] ?? null),
            'abandonAttempts' => $this->intOf($row[TestOrderInterface::ABANDON_ATTEMPTS] ?? null),
        ]);
    }

    /**
     * The table's name with the installation's prefix.
     *
     * @return string
     */
    private function table(): string
    {
        return $this->resourceConnection->getTableName(self::TABLE_NAME);
    }

    /**
     * The current UTC time in the table's format.
     *
     * @return string
     */
    private function now(): string
    {
        return $this->clock->gmtDate(self::DATE_FORMAT);
    }

    /**
     * A stored integer column; the database driver hands integers over as strings.
     *
     * @param mixed $value Column value
     * @return int Zero for anything that is not an integer, which the record refuses
     */
    private function intOf(mixed $value): int
    {
        return is_numeric($value) ? (int)$value : 0;
    }

    /**
     * A stored nullable integer column.
     *
     * @param mixed $value Column value
     * @return int|null
     */
    private function nullableIntOf(mixed $value): ?int
    {
        return $value === null ? null : $this->intOf($value);
    }

    /**
     * A stored text column.
     *
     * @param mixed $value Column value
     * @return string Empty for anything that is not text, which the record refuses
     */
    private function stringOf(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }
}
