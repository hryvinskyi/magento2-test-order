<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\TestOrder\Model;

use Hryvinskyi\TestOrder\Api\CartGuardInterface;
use Magento\Framework\Phrase;
use Magento\Quote\Api\Data\CartInterface;

/**
 * Asks every contributed cart guard, in the pool's order, and passes on the first refusal.
 *
 * With no guard contributed nothing is refused.
 */
class CartGuardPool implements CartGuardInterface
{
    /**
     * @var list<CartGuardInterface>
     */
    private readonly array $guards;

    /**
     * @param array<mixed> $guards Cart guards by name, in the order they are asked
     * @throws \InvalidArgumentException When an item of the pool is not a cart guard
     */
    public function __construct(array $guards = [])
    {
        $checked = [];
        foreach ($guards as $name => $guard) {
            if (!$guard instanceof CartGuardInterface) {
                throw new \InvalidArgumentException(sprintf(
                    'The test-order cart guard "%s" does not implement %s.',
                    $name,
                    CartGuardInterface::class
                ));
            }
            $checked[] = $guard;
        }
        $this->guards = $checked;
    }

    /**
     * @inheritDoc
     */
    public function refusalForCart(CartInterface $cart, ?string $paymentMethod): ?Phrase
    {
        foreach ($this->guards as $guard) {
            $refusal = $guard->refusalForCart($cart, $paymentMethod);
            if ($refusal !== null) {
                return $refusal;
            }
        }

        return null;
    }
}
