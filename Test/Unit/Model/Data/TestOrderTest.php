<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\TestOrder\Test\Unit\Model\Data;

use Hryvinskyi\TestOrder\Model\Data\TestOrder;
use PHPUnit\Framework\TestCase;

/**
 * A record names a stored row, a cart and, once linked, an order; it is undone once it carries the time it was.
 */
class TestOrderTest extends TestCase
{
    /**
     * A record that has not been linked and was never tried by the clean-up job.
     *
     * @return void
     */
    public function testAFreshRecordIsUnlinkedAndUntried(): void
    {
        $record = new TestOrder(7, 42, '2026-10-02 10:00:00');

        self::assertSame(7, $record->getEntityId());
        self::assertSame(42, $record->getQuoteId());
        self::assertSame('2026-10-02 10:00:00', $record->getRecognisedAt());
        self::assertNull($record->getOrderId());
        self::assertSame(0, $record->getAbandonAttempts());
    }

    /**
     * A linked record with failed abandon attempts says so.
     *
     * @return void
     */
    public function testALinkedRecordWithFailedAttemptsSaysSo(): void
    {
        $record = new TestOrder(7, 42, '2026-10-02 10:00:00', 1001, 3);

        self::assertSame(1001, $record->getOrderId());
        self::assertSame(3, $record->getAbandonAttempts());
    }

    /**
     * A record that names nothing real cannot be made.
     *
     * @dataProvider invalidRecords
     * @param int $entityId Record id
     * @param int $quoteId Cart id
     * @param string $recognisedAt Recognition time
     * @param int|null $orderId Order id
     * @param int $attempts Failed abandon attempts
     * @return void
     */
    public function testARecordThatNamesNothingRealIsRefused(
        int $entityId,
        int $quoteId,
        string $recognisedAt,
        ?int $orderId,
        int $attempts
    ): void {
        $this->expectException(\InvalidArgumentException::class);

        new TestOrder($entityId, $quoteId, $recognisedAt, $orderId, $attempts);
    }

    /**
     * @return array<string, array{0: int, 1: int, 2: string, 3: int|null, 4: int}>
     */
    public static function invalidRecords(): array
    {
        return [
            'no record id' => [0, 42, '2026-10-02 10:00:00', null, 0],
            'no cart' => [7, 0, '2026-10-02 10:00:00', null, 0],
            'negative order' => [7, 42, '2026-10-02 10:00:00', -1, 0],
            'zero order' => [7, 42, '2026-10-02 10:00:00', 0, 0],
            'no recognition time' => [7, 42, '', null, 0],
            'negative attempts' => [7, 42, '2026-10-02 10:00:00', null, -1],
        ];
    }
}
