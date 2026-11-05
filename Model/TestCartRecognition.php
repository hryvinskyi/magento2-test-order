<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\TestOrder\Model;

use Hryvinskyi\TestOrder\Api\CartGuardInterface;
use Hryvinskyi\TestOrder\Api\CartOrdersInterface;
use Hryvinskyi\TestOrder\Api\RecognitionGuardInterface;
use Hryvinskyi\TestOrder\Api\RequestProofInterface;
use Hryvinskyi\TestOrder\Api\TestCartRecognitionInterface;
use Hryvinskyi\TestOrder\Api\TestCartRecordsInterface;
use Hryvinskyi\TestOrder\Api\TestCartsInterface;
use Hryvinskyi\TestOrder\Api\TestCustomersInterface;
use Hryvinskyi\TestOrder\Domain\TestOrderAction;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Api\Data\CartInterface;
use Magento\Quote\Api\Data\PaymentInterface;
use Magento\Quote\Api\PaymentMethodManagementInterface;
use Magento\Quote\Model\QuoteIdToMaskedQuoteIdInterface;

/**
 * What may become a test cart.
 *
 * The proof comes first: it must be well formed, made with the shop's secret for placing over the cart's masked id,
 * and fresh. Only then is anything said about the cart. Its owner must be one that may hold a test cart: a guest, or a
 * customer the deployment names as a test customer. The cart must be open and have no ordinary order, no recognition
 * guard may object in its store and no cart guard may object to it. Then the cart is recorded as a test cart, so every
 * guarded integration ignores the order from the moment it is placed.
 *
 * A proof that cannot be honoured — malformed, wrong, stale, made for another action, the shop has no secret, the cart
 * of a customer the deployment does not name, a closed cart, a cart with an ordinary order, or a guard's refusal — is
 * refused. A runner that believes it places a test order must never create a real one, and a proof must never turn a
 * customer's order into a test order: a cart keeps its masked id after its order is placed.
 *
 * Without a valid proof the refusal is the same for every id. A cart with no masked id and a cart that does not exist
 * get it too, as no proof can be valid for them: otherwise an anonymous caller could tell which ids are carts, and
 * whose.
 */
class TestCartRecognition implements TestCartRecognitionInterface
{
    /**
     * @param RequestProofInterface $requestProof The proof sent with the request being served
     * @param QuoteIdToMaskedQuoteIdInterface $maskedQuoteIds The cart id the proof is made over
     * @param CartRepositoryInterface $carts Names the cart's owner and its store
     * @param RecognitionGuardInterface $recognitionGuard Every guard that may veto test orders in the cart's store
     * @param TestCartRecordsInterface $records Records the recognised cart
     * @param CartOrdersInterface $cartOrders Tells whether an order was already placed from the cart
     * @param TestCartsInterface $testCarts Tells a retried test cart from a cart with an ordinary order
     * @param TestCustomersInterface $testCustomers Tells whether the deployment names the cart's owner
     * @param CartGuardInterface $cartGuard Every guard that may veto this cart becoming a test cart
     * @param PaymentMethodManagementInterface $paymentMethods Tells the method saved on the cart
     */
    public function __construct(
        private readonly RequestProofInterface $requestProof,
        private readonly QuoteIdToMaskedQuoteIdInterface $maskedQuoteIds,
        private readonly CartRepositoryInterface $carts,
        private readonly RecognitionGuardInterface $recognitionGuard,
        private readonly TestCartRecordsInterface $records,
        private readonly CartOrdersInterface $cartOrders,
        private readonly TestCartsInterface $testCarts,
        private readonly TestCustomersInterface $testCustomers,
        private readonly CartGuardInterface $cartGuard,
        private readonly PaymentMethodManagementInterface $paymentMethods
    ) {
    }

    /**
     * @inheritDoc
     */
    public function recognise(int $quoteId, ?string $paymentMethod = null): void
    {
        $maskedId = $this->maskedIdOf($quoteId);
        if ($maskedId === '' || !$this->requestProof->isValidFor(TestOrderAction::Place, $maskedId)) {
            throw new LocalizedException(
                __('The test-order proof sent with this order could not be verified, so the order was not placed.')
            );
        }

        $cart = $this->carts->get($quoteId);
        if (!$this->ownerMayHoldATestCart($cart)) {
            throw new LocalizedException(
                __("A test order can only be placed from a guest cart or a test customer's cart, so the order was not placed.")
            );
        }

        if (!$cart->getIsActive() || $this->hasOrdinaryOrder($quoteId)) {
            throw new LocalizedException(
                __('A test order can only be placed from an open cart that has no ordinary order, so the order was not placed.')
            );
        }

        $refusal = $this->recognitionGuard->refusalFor((int)$cart->getStoreId())
            ?? $this->cartGuard->refusalForCart(
                $cart,
                $paymentMethod ?? $this->methodOf($this->paymentMethods->get($quoteId))
            );
        if ($refusal !== null) {
            throw new LocalizedException($refusal);
        }

        $this->records->recognise($quoteId);
    }

    /**
     * The masked id a proof for the cart is made over.
     *
     * @param int $quoteId Cart (quote) entity id
     * @return string Empty when the cart has none, or does not exist
     */
    private function maskedIdOf(int $quoteId): string
    {
        try {
            return $this->maskedQuoteIds->execute($quoteId);
        } catch (NoSuchEntityException) {
            return '';
        }
    }

    /**
     * The method of the payment saved on a cart.
     *
     * The payment contract declares a payment, and the core answers null for a cart with none saved.
     *
     * @param PaymentInterface|null $payment The cart's saved payment
     * @return string|null Null when the cart has no payment saved
     */
    private function methodOf(?PaymentInterface $payment): ?string
    {
        return $payment?->getMethod();
    }

    /**
     * Whether the cart's owner may hold a test cart: it names no customer, or a test customer.
     *
     * A signed-in customer's cart can carry a masked id too (GraphQL gives it one), so the id alone tells nothing about
     * the owner. The customer is told by the account's e-mail, never by the cart's own `customer_email`, which a
     * checkout may write from what the visitor typed.
     *
     * @param CartInterface $cart The cart being placed
     * @return bool
     */
    private function ownerMayHoldATestCart(CartInterface $cart): bool
    {
        $customer = $cart->getCustomer();

        return (int)$customer->getId() <= 0 || $this->testCustomers->isTestCustomer((string)$customer->getEmail());
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
