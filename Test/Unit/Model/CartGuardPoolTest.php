<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\TestOrder\Test\Unit\Model;

use Hryvinskyi\TestOrder\Api\CartGuardInterface;
use Hryvinskyi\TestOrder\Model\CartGuardPool;
use Magento\Framework\Phrase;
use Magento\Quote\Api\Data\CartInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The pool asks its guards in order and passes on the first refusal; with no guard, or none objecting, nothing is
 * refused; an item that is no guard is refused when the pool is built.
 */
class CartGuardPoolTest extends TestCase
{
    private const string METHOD = 'a_payment_method';

    /**
     * @var CartInterface&MockObject
     */
    private CartInterface&MockObject $cart;

    /**
     * @inheritDoc
     */
    protected function setUp(): void
    {
        $this->cart = $this->createMock(CartInterface::class);
    }

    /**
     * Without a guard nothing is refused.
     *
     * @return void
     */
    public function testAnEmptyPoolRefusesNothing(): void
    {
        self::assertNull((new CartGuardPool())->refusalForCart($this->cart, self::METHOD));
    }

    /**
     * When no guard objects, nothing is refused, and every guard was asked about the cart.
     *
     * @return void
     */
    public function testNoObjectionRefusesNothing(): void
    {
        $pool = new CartGuardPool(['first' => $this->guard(null), 'second' => $this->guard(null)]);

        self::assertNull($pool->refusalForCart($this->cart, self::METHOD));
    }

    /**
     * The first guard that objects decides; the guards after it are not asked.
     *
     * @return void
     */
    public function testTheFirstRefusalIsPassedOn(): void
    {
        $unasked = $this->createMock(CartGuardInterface::class);
        $unasked->expects(self::never())->method('refusalForCart');
        $pool = new CartGuardPool([
            'quiet' => $this->guard(null),
            'objecting' => $this->guard('This cart pays on live keys.'),
            'later' => $unasked,
        ]);

        self::assertSame('This cart pays on live keys.', (string)$pool->refusalForCart($this->cart, self::METHOD));
    }

    /**
     * A cart that names no method yet reaches each guard as null: it is an ordinary answer, not a refusal.
     *
     * @return void
     */
    public function testNoMethodIsPassedOnAsNull(): void
    {
        $guard = $this->createMock(CartGuardInterface::class);
        $guard->expects(self::once())->method('refusalForCart')->with($this->cart, null)->willReturn(null);

        self::assertNull((new CartGuardPool(['only' => $guard]))->refusalForCart($this->cart, null));
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

        new CartGuardPool(['stray' => new \stdClass()]);
    }

    /**
     * A guard that answers the same for the cart.
     *
     * @param string|null $refusal Its reason; null when it has no objection
     * @return CartGuardInterface
     */
    private function guard(?string $refusal): CartGuardInterface
    {
        $guard = $this->createMock(CartGuardInterface::class);
        $guard->expects(self::once())->method('refusalForCart')->with($this->cart, self::METHOD)
            ->willReturn($refusal === null ? null : new Phrase($refusal));

        return $guard;
    }
}
