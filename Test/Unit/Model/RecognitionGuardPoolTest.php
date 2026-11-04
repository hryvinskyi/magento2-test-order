<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\TestOrder\Test\Unit\Model;

use Hryvinskyi\TestOrder\Api\RecognitionGuardInterface;
use Hryvinskyi\TestOrder\Model\RecognitionGuardPool;
use Magento\Framework\Phrase;
use PHPUnit\Framework\TestCase;

/**
 * The pool asks its guards in order and passes on the first refusal; with no guard, or none objecting, nothing is
 * refused; an item that is no guard is refused when the pool is built.
 */
class RecognitionGuardPoolTest extends TestCase
{
    private const int STORE_ID = 4;

    /**
     * Without a guard nothing is refused.
     *
     * @return void
     */
    public function testAnEmptyPoolRefusesNothing(): void
    {
        self::assertNull((new RecognitionGuardPool())->refusalFor(self::STORE_ID));
    }

    /**
     * When no guard objects, nothing is refused, and every guard was asked about the store.
     *
     * @return void
     */
    public function testNoObjectionRefusesNothing(): void
    {
        $pool = new RecognitionGuardPool(['first' => $this->guard(null), 'second' => $this->guard(null)]);

        self::assertNull($pool->refusalFor(self::STORE_ID));
    }

    /**
     * The first guard that objects decides; the guards after it are not asked.
     *
     * @return void
     */
    public function testTheFirstRefusalIsPassedOn(): void
    {
        $unasked = $this->createMock(RecognitionGuardInterface::class);
        $unasked->expects(self::never())->method('refusalFor');
        $pool = new RecognitionGuardPool([
            'quiet' => $this->guard(null),
            'objecting' => $this->guard('Payments here run on live keys.'),
            'later' => $unasked,
        ]);

        self::assertSame('Payments here run on live keys.', (string)$pool->refusalFor(self::STORE_ID));
    }

    /**
     * Something in the pool that is not a guard is refused at once, naming the item.
     *
     * @return void
     */
    public function testAPoolItemThatIsNoGuardIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('"stray"');

        new RecognitionGuardPool(['stray' => new \stdClass()]);
    }

    /**
     * A guard that answers the same for the store.
     *
     * @param string|null $refusal Its reason; null when it has no objection
     * @return RecognitionGuardInterface
     */
    private function guard(?string $refusal): RecognitionGuardInterface
    {
        $guard = $this->createMock(RecognitionGuardInterface::class);
        $guard->expects(self::once())->method('refusalFor')->with(self::STORE_ID)
            ->willReturn($refusal === null ? null : new Phrase($refusal));

        return $guard;
    }
}
