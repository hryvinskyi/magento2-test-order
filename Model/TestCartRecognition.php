<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\TestOrder\Model;

use Hryvinskyi\TestOrder\Api\CartOrdersInterface;
use Hryvinskyi\TestOrder\Api\RecognitionGuardInterface;
use Hryvinskyi\TestOrder\Api\RequestProofInterface;
use Hryvinskyi\TestOrder\Api\TestCartRecognitionInterface;
use Hryvinskyi\TestOrder\Api\TestCartRecordsInterface;
use Hryvinskyi\TestOrder\Api\TestCartsInterface;
use Hryvinskyi\TestOrder\Domain\TestOrderAction;
use Magento\Framework\Exception\LocalizedException;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Api\Data\CartInterface;
use Magento\Quote\Model\QuoteIdToMaskedQuoteIdInterface;

/**
 * What may become a test cart.
 *
 * The cart must be a guest cart, the proof must be well formed, made with the shop's secret for placing over the cart's
 * masked id, and fresh, the cart must be open and have no ordinary order, and no recognition guard may object in the
 * cart's store. Then the cart is recorded as a test cart, so every guarded integration ignores the order from the moment
 * it is placed.
 *
 * A proof that cannot be honoured — malformed, wrong, stale, made for another action, the shop has no secret, a signed-in
 * customer's cart, a closed cart, a cart with an ordinary order, or a guard's refusal — is refused. A runner that believes
 * it places a test order must never create a real one, and a proof must never turn a customer's order into a test order:
 * a guest cart keeps its masked id after its order is placed.
 */
class TestCartRecognition implements TestCartRecognitionInterface
{
    /**
     * @param RequestProofInterface $requestProof The proof sent with the request being served
     * @param QuoteIdToMaskedQuoteIdInterface $maskedQuoteIds The guest cart id the proof is made over
     * @param CartRepositoryInterface $carts Tells a signed-in customer's cart from a guest cart, and names its store
     * @param RecognitionGuardInterface $recognitionGuard Every guard that may veto test orders in the cart's store
     * @param TestCartRecordsInterface $records Records the recognised cart
     * @param CartOrdersInterface $cartOrders Tells whether an order was already placed from the cart
     * @param TestCartsInterface $testCarts Tells a retried test cart from a cart with an ordinary order
     */
    public function __construct(
        private readonly RequestProofInterface $requestProof,
        private readonly QuoteIdToMaskedQuoteIdInterface $maskedQuoteIds,
        private readonly CartRepositoryInterface $carts,
        private readonly RecognitionGuardInterface $recognitionGuard,
        private readonly TestCartRecordsInterface $records,
        private readonly CartOrdersInterface $cartOrders,
        private readonly TestCartsInterface $testCarts
    ) {
    }

    /**
     * @inheritDoc
     */
    public function recognise(int $quoteId): void
    {
        $maskedId = $this->maskedQuoteIds->execute($quoteId);
        $cart = $this->carts->get($quoteId);
        if (!$this->isGuestCart($cart, $maskedId)) {
            throw new LocalizedException(
                __('A test order can only be placed from a guest cart, so the order was not placed.')
            );
        }

        if (!$this->requestProof->isValidFor(TestOrderAction::Place, $maskedId)) {
            throw new LocalizedException(
                __('The test-order proof sent with this order could not be verified, so the order was not placed.')
            );
        }

        if (!$cart->getIsActive() || $this->hasOrdinaryOrder($quoteId)) {
            throw new LocalizedException(
                __('A test order can only be placed from an open cart that has no ordinary order, so the order was not placed.')
            );
        }

        $refusal = $this->recognitionGuard->refusalFor((int)$cart->getStoreId());
        if ($refusal !== null) {
            throw new LocalizedException($refusal);
        }

        $this->records->recognise($quoteId);
    }

    /**
     * Whether the cart is a guest cart: it has a masked id and names no customer.
     *
     * A signed-in customer's cart can carry a masked id too (GraphQL gives it one), so a guest cart is told by both.
     *
     * @param CartInterface $cart The cart being placed
     * @param string $maskedId The cart's masked id; empty when it has none
     * @return bool
     */
    private function isGuestCart(CartInterface $cart, string $maskedId): bool
    {
        return $maskedId !== '' && (int)$cart->getCustomer()->getId() <= 0;
    }

    /**
     * Whether an order exists of a cart that is not a test cart.
     *
     * A test cart may place again: a placement can save an order and then fail, and the retry places another.
     *
     * @param int $quoteId Cart (quote) entity id
     * @return bool
     */
    private function hasOrdinaryOrder(int $quoteId): bool
    {
        return !$this->testCarts->isTestCart($quoteId) && $this->cartOrders->ofCart($quoteId) !== [];
    }
}
