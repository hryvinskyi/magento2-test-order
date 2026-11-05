<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\TestOrder\Plugin;

use Hryvinskyi\TestOrder\Api\TestCartPlacementInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Quote\Api\CartManagementInterface;
use Magento\Quote\Api\Data\PaymentInterface;

/**
 * Makes every REST and GraphQL placement a {@see TestCartPlacementInterface} placement.
 *
 * Each of them passes through {@see CartManagementInterface::placeOrder()} with the cart's real id, so a proof sent
 * with one is honoured here. What a placement does with a proof is {@see TestCartPlacementInterface}'s to say.
 */
class RecogniseTestCart
{
    /**
     * @param TestCartPlacementInterface $placement Runs a placement, honouring a proof sent with the request
     */
    public function __construct(
        private readonly TestCartPlacementInterface $placement
    ) {
    }

    /**
     * Place the order as a test order when a proof is sent, or refuse the placement.
     *
     * Runs before every other plugin on placement, so a refused placement creates no order, and a failure anywhere
     * further in passes through here. What ran before placement stays done: a REST route that takes the payment
     * information saves it first.
     *
     * @param CartManagementInterface $subject Places the order
     * @param callable $proceed The placement itself
     * @param int|string $cartId Cart (quote) entity id
     * @param PaymentInterface|null $paymentMethod Payment chosen at placement
     * @return mixed The placed order's id, exactly as placement returned it
     * @throws LocalizedException When a proof is sent and cannot be honoured; no order has been created
     * @throws \Throwable Whatever the placement threw, unchanged
     */
    public function aroundPlaceOrder(
        CartManagementInterface $subject,
        callable $proceed,
        int|string $cartId,
        ?PaymentInterface $paymentMethod = null
    ): mixed {
        return $this->placement->place(
            (int)$cartId,
            static fn (): mixed => $proceed($cartId, $paymentMethod),
            $paymentMethod?->getMethod()
        );
    }
}
