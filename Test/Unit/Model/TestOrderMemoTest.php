<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\TestOrder\Test\Unit\Model;

use Hryvinskyi\TestOrder\Model\TestOrderMemo;
use PHPUnit\Framework\TestCase;

/**
 * Kept answers are given back until a write makes them wrong, and forgotten between requests.
 */
class TestOrderMemoTest extends TestCase
{
    /**
     * Nothing is known before anything is kept.
     *
     * @return void
     */
    public function testNothingIsKnownAtFirst(): void
    {
        $memo = new TestOrderMemo();

        self::assertNull($memo->forQuote(42));
        self::assertNull($memo->forOrder(1001));
    }

    /**
     * Kept answers come back as kept.
     *
     * @return void
     */
    public function testKeptAnswersComeBack(): void
    {
        $memo = new TestOrderMemo();
        $memo->rememberQuote(42, false);
        $memo->rememberOrder(1001, true);

        self::assertFalse($memo->forQuote(42));
        self::assertTrue($memo->forOrder(1001));
    }

    /**
     * Forgetting a cart after a failed placement turns an earlier "yes" for it into "no", and leaves other carts alone.
     *
     * @return void
     */
    public function testForgettingACartTurnsItsYesIntoNo(): void
    {
        $memo = new TestOrderMemo();
        $memo->recognised(42);
        $memo->recognised(43);

        $memo->forgotten(42);

        self::assertFalse($memo->forQuote(42));
        self::assertTrue($memo->forQuote(43));
    }

    /**
     * Recognising a cart turns an earlier "no" for it into "yes", and forgets every "no" kept for an order, since
     * such an order may come from that cart; a kept "yes" for an order stays.
     *
     * @return void
     */
    public function testRecognisingACartOverridesEarlierNos(): void
    {
        $memo = new TestOrderMemo();
        $memo->rememberQuote(42, false);
        $memo->rememberOrder(1001, false);
        $memo->rememberOrder(1002, true);

        $memo->recognised(42);

        self::assertTrue($memo->forQuote(42));
        self::assertNull($memo->forOrder(1001));
        self::assertTrue($memo->forOrder(1002));
    }

    /**
     * Linking an order answers "yes" for the order and its cart.
     *
     * @return void
     */
    public function testLinkingAnOrderAnswersYesForBoth(): void
    {
        $memo = new TestOrderMemo();
        $memo->rememberOrder(1001, false);

        $memo->linked(42, 1001);

        self::assertTrue($memo->forQuote(42));
        self::assertTrue($memo->forOrder(1001));
    }

    /**
     * A new request starts with nothing kept.
     *
     * @return void
     */
    public function testANewRequestStartsAfresh(): void
    {
        $memo = new TestOrderMemo();
        $memo->recognised(42);
        $memo->rememberOrder(1001, true);

        $memo->_resetState();

        self::assertNull($memo->forQuote(42));
        self::assertNull($memo->forOrder(1001));
    }
}
