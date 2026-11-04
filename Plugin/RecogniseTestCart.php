<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\TestOrder\Plugin;

use Hryvinskyi\TestOrder\Api\RequestProofInterface;
use Hryvinskyi\TestOrder\Api\TestCartRecognitionInterface;
use Hryvinskyi\TestOrder\Api\TestCartRecordsInterface;
use Hryvinskyi\TestOrder\Api\TestCartsInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Quote\Api\CartManagementInterface;
use Magento\Quote\Api\Data\PaymentInterface;
use Psr\Log\LoggerInterface;

/**
 * Has a cart recognised as a test cart when its order is placed with a proof, before the order is created, and forgets
 * the record again when the placement fails.
 *
 * Every REST and GraphQL placement passes through {@see CartManagementInterface::placeOrder()} with the cart's real
 * id, so this is the one place where a proof sent with a placement is honoured.
 *
 * - No proof sent: an ordinary order; nothing here happens.
 * - A proof sent: the placement goes ahead only as a test order, or is refused. What may become a test cart is
 *   {@see TestCartRecognitionInterface}'s to decide.
 * - A placement that fails forgets only a record it made itself, and only while no order of the cart exists (one the
 *   placement saved before it failed stays a test order). A record that was already there when it started is another
 *   placement's, perhaps one still running, and stays. The failure is passed on unchanged.
 */
class RecogniseTestCart
{
    /**
     * @param RequestProofInterface $requestProof Tells whether a proof was sent with the request being served
     * @param TestCartRecognitionInterface $recognition Records the cart as a test cart, or refuses
     * @param TestCartsInterface $testCarts Tells whether the cart was already recorded when the placement started
     * @param TestCartRecordsInterface $records Forgets the record a failed placement made
     * @param LoggerInterface $logger Where a record that could not be forgotten is reported
     */
    public function __construct(
        private readonly RequestProofInterface $requestProof,
        private readonly TestCartRecognitionInterface $recognition,
        private readonly TestCartsInterface $testCarts,
        private readonly TestCartRecordsInterface $records,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Place the order as a test order when a proof is sent, or refuse the placement.
     *
     * Runs before every other plugin on placement, so a refused placement creates no order, and a failure anywhere
     * further in passes through here. What ran before placement stays done: the guest REST route saves the payment
     * information first.
     *
     * @param CartManagementInterface $subject Places the order
     * @param callable $proceed The placement itself
     * @param int|string $cartId Cart (quote) entity id
     * @param PaymentInterface|null $paymentMethod Payment chosen at placement
     * @return mixed The placed order's id, exactly as placement returned it
     * @throws LocalizedException When a proof is sent and cannot be honoured; no order has been created
     * @throws NoSuchEntityException When the cart does not exist
     * @throws \Throwable Whatever the placement threw, unchanged
     */
    public function aroundPlaceOrder(
        CartManagementInterface $subject,
        callable $proceed,
        int|string $cartId,
        ?PaymentInterface $paymentMethod = null
    ): mixed {
        if (!$this->requestProof->isSent()) {
            return $proceed($cartId, $paymentMethod);
        }

        $quoteId = (int)$cartId;
        $wasRecorded = $this->testCarts->isTestCart($quoteId);
        $this->recognition->recognise($quoteId);

        try {
            return $proceed($cartId, $paymentMethod);
        } catch (\Throwable $failure) {
            // A failed placement forgets only a record it made itself. One that was already there is another
            // placement's, perhaps one still running whose order is not saved yet: forgetting it would turn that
            // order into an ordinary one.
            if (!$wasRecorded) {
                $this->forgetOrderless($quoteId);
            }

            throw $failure;
        }
    }

    /**
     * Forget the cart's record after a failed placement, unless an order of the cart exists.
     *
     * Never throws: the placement's own failure is what the caller must see.
     *
     * @param int $quoteId Cart (quote) entity id
     * @return void
     */
    private function forgetOrderless(int $quoteId): void
    {
        try {
            $this->records->forgetOrderless($quoteId);
        } catch (\Throwable $failure) {
            $this->logger->warning(
                sprintf(
                    'Placing the test order of cart %d failed, and its record could not be removed: %s',
                    $quoteId,
                    $failure->getMessage()
                ),
                ['exception' => $failure]
            );
        }
    }
}
