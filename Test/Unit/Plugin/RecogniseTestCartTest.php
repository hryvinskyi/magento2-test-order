<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\TestOrder\Test\Unit\Plugin;

use Hryvinskyi\TestOrder\Api\TestCartPlacementInterface;
use Hryvinskyi\TestOrder\Plugin\RecogniseTestCart;
use Magento\Framework\Exception\LocalizedException;
use Magento\Quote\Api\CartManagementInterface;
use Magento\Quote\Api\Data\PaymentInterface;
use PHPUnit\Framework\TestCase;

/**
 * Every placement is handed to {@see TestCartPlacementInterface} by the cart's real id, with the core's placement as
 * what it wraps. What a proof does to a placement is
 * {@see \Hryvinskyi\TestOrder\Test\Unit\Model\TestCartPlacementTest}'s.
 */
class RecogniseTestCartTest extends TestCase
{
    private const int QUOTE_ID = 4711;

    /**
     * The placement runs inside the port, with the arguments the caller gave and whichever way the id arrived, and its
     * answer is handed back as it is.
     *
     * @dataProvider cartIds
     * @param int|string $cartId Cart id as the placement passes it
     * @return void
     */
    public function testThePlacementRunsInsideThePort(int|string $cartId): void
    {
        $payment = $this->createStub(PaymentInterface::class);
        $placement = $this->createMock(TestCartPlacementInterface::class);
        $placement->expects(self::once())->method('place')->with(self::QUOTE_ID)
            ->willReturnCallback(static fn (int|string $id, callable $wrapped): mixed => $wrapped());
        $placed = [];

        $answer = (new RecogniseTestCart($placement))->aroundPlaceOrder(
            $this->createStub(CartManagementInterface::class),
            static function (int|string $id, ?PaymentInterface $method) use (&$placed): string {
                $placed[] = [$id, $method];

                return '812';
            },
            $cartId,
            $payment
        );

        self::assertSame('812', $answer);
        self::assertSame([[$cartId, $payment]], $placed);
    }

    /**
     * The method named at placement goes to the port, which the cart does not know of yet.
     *
     * @return void
     */
    public function testTheMethodNamedAtPlacementIsPassedOn(): void
    {
        $payment = $this->createStub(PaymentInterface::class);
        $payment->method('getMethod')->willReturn('a_payment_method');
        $placement = $this->createMock(TestCartPlacementInterface::class);
        $placement->expects(self::once())->method('place')->with(self::QUOTE_ID, self::anything(), 'a_payment_method');

        (new RecogniseTestCart($placement))->aroundPlaceOrder(
            $this->createStub(CartManagementInterface::class),
            static fn (): string => '812',
            self::QUOTE_ID,
            $payment
        );
    }

    /**
     * A placement that names no method names none to the port.
     *
     * @return void
     */
    public function testAPlacementNamingNoMethodPassesNone(): void
    {
        $placement = $this->createMock(TestCartPlacementInterface::class);
        $placement->expects(self::once())->method('place')->with(self::QUOTE_ID, self::anything(), null);

        (new RecogniseTestCart($placement))->aroundPlaceOrder(
            $this->createStub(CartManagementInterface::class),
            static fn (): string => '812',
            self::QUOTE_ID
        );
    }

    /**
     * The cart id as each area of the core hands it to placement.
     *
     * @return array<string, array{int|string}>
     */
    public static function cartIds(): array
    {
        return [
            'integer' => [self::QUOTE_ID],
            'string' => [(string)self::QUOTE_ID],
        ];
    }

    /**
     * A refusal by the port reaches the caller, and the placement does not run.
     *
     * @return void
     */
    public function testARefusalReachesTheCallerAndNothingIsPlaced(): void
    {
        $refusal = new LocalizedException(__('The order was not placed.'));
        $placement = $this->createStub(TestCartPlacementInterface::class);
        $placement->method('place')->willThrowException($refusal);

        $this->expectExceptionObject($refusal);

        (new RecogniseTestCart($placement))->aroundPlaceOrder(
            $this->createStub(CartManagementInterface::class),
            static function (): never {
                self::fail('A refused placement ran.');
            },
            self::QUOTE_ID
        );
    }
}
