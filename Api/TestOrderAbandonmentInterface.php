<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\TestOrder\Api;

/**
 * The end-to-end runner ends the test orders it placed from a guest cart: `POST /V1/test-orders/:cartId/abandon`.
 *
 * The route is open to anonymous callers; the authorisation is the runner's proof, sent in the
 * `X-Test-Order-Proof` header and made for the action `abandon` over the guest cart's masked id.
 *
 * @api
 */
interface TestOrderAbandonmentInterface
{
    /**
     * Abandon every test order placed from the guest cart, and answer with one left open when there is one, otherwise
     * with the newest; `deleted` is true only when every order of the cart was deleted.
     *
     * `state` is `canceled` once the order is cancelled. Any other state means the order could no longer be cancelled:
     * it was undone, kept and logged for a person to end.
     *
     * @param string $cartId The guest cart's masked id
     * @return \Hryvinskyi\TestOrder\Api\Data\AbandonResultInterface
     * @throws \Magento\Framework\Exception\AuthorizationException When no valid proof for the cart was sent
     * @throws \Magento\Framework\Exception\NoSuchEntityException When the cart is unknown, was never recognised,
     *                                                            or no order was placed from it
     * @throws \Magento\Framework\Exception\LocalizedException When cancelling or deleting an order fails
     */
    public function abandon(string $cartId): \Hryvinskyi\TestOrder\Api\Data\AbandonResultInterface;
}
