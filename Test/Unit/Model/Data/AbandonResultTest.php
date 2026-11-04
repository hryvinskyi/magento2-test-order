<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\TestOrder\Test\Unit\Model\Data;

use Hryvinskyi\TestOrder\Model\Data\AbandonResult;
use PHPUnit\Framework\TestCase;

/**
 * What abandoning did is told as given, and an answer without a state, or with an empty number, cannot exist.
 */
class AbandonResultTest extends TestCase
{
    /**
     * The answer holds what it was given; the number may be absent.
     *
     * @return void
     */
    public function testTheAnswerHoldsWhatItWasGiven(): void
    {
        $deleted = new AbandonResult('canceled', true, '100000042');
        $unnumbered = new AbandonResult('processing', false);

        self::assertSame(['100000042', 'canceled', true], [
            $deleted->getIncrementId(),
            $deleted->getState(),
            $deleted->isDeleted(),
        ]);
        self::assertSame([null, 'processing', false], [
            $unnumbered->getIncrementId(),
            $unnumbered->getState(),
            $unnumbered->isDeleted(),
        ]);
    }

    /**
     * An empty state or an empty number is refused.
     *
     * @dataProvider invalidAnswers
     * @param string $state State
     * @param string|null $incrementId Number
     * @return void
     */
    public function testAnIncompleteAnswerIsRefused(string $state, ?string $incrementId): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new AbandonResult($state, false, $incrementId);
    }

    /**
     * @return array<string, array{string, string|null}>
     */
    public static function invalidAnswers(): array
    {
        return [
            'no state' => ['', '100000042'],
            'an empty number' => ['canceled', ''],
        ];
    }
}
