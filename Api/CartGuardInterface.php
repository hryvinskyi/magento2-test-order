<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\TestOrder\Api;

use Magento\Framework\Phrase;
use Magento\Quote\Api\Data\CartInterface;

/**
 * Vetoes one cart becoming a test cart, for what only the cart can tell.
 *
 * A {@see RecognitionGuardInterface} answers for a whole store, so it cannot tell a live payment method from a
 * test-mode one beside it in the same store. This guard sees the cart.
 *
 * Guards form an open pool (the `guards` argument of {@see \Hryvinskyi\TestOrder\Model\CartGuardPool}): the module that
 * knows what is unsafe contributes its guard with a `di.xml` item. The pool is asked last, behind a valid proof and the
 * store guards, so nobody without a proof learns anything from it. Any one refusal refuses the placement with its
 * reason, and nothing is recorded.
 *
 * The cart is the one saved before the placement started, which does not yet hold a method the placement names in the
 * same call, so the method comes as an argument. A checkout that chooses the method later names none yet, and null is
 * an ordinary answer.
 *
 * A guard sees only what is known in the request that carries the proof. Within that request a checkout passes the
 * method to {@see TestCartPlacementInterface::place()} when it knows it, and an inner call of a nested placement asks
 * the guards again with what is known by then. A placement in a request with no proof runs at once and asks no guard,
 * so a method chosen in a later request never reaches this one and needs a {@see RecognitionGuardInterface}.
 *
 * @api
 */
interface CartGuardInterface
{
    /**
     * Why the cart may not become a test cart, if it may not.
     *
     * @param CartInterface $cart The cart being placed
     * @param string|null $paymentMethod The method the cart will be paid with as far as known here: the one the
     *                                   placement names, otherwise the one saved on the cart; null when neither
     *                                   names one
     * @return Phrase|null Null when the guard has no objection; otherwise the reason, a full sentence for the person
     *                     running the tests
     */
    public function refusalForCart(CartInterface $cart, ?string $paymentMethod): ?Phrase;
}
