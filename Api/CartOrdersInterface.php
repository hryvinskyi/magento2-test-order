<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\TestOrder\Api;

use Magento\Sales\Api\Data\OrderInterface;

/**
 * The orders placed from one cart.
 *
 * Found through the cart id every order carries, never through a link written afterwards: placement can save an
 * order and then fail before anything links it.
 */
interface CartOrdersInterface
{
    /**
     * Every order placed from the cart, oldest first.
     *
     * @param int $quoteId Cart (quote) entity id
     * @return list<OrderInterface> Empty when no order was placed from the cart
     */
    public function ofCart(int $quoteId): array;
}
