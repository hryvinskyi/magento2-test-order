<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\TestOrder\Model;

use Hryvinskyi\TestOrder\Api\RequestProofInterface;
use Hryvinskyi\TestOrder\Api\TestCartPlacementInterface;
use Hryvinskyi\TestOrder\Api\TestCartRecognitionInterface;
use Hryvinskyi\TestOrder\Api\TestCartRecordsInterface;
use Hryvinskyi\TestOrder\Api\TestCartsInterface;
use Magento\Quote\Model\MaskedQuoteIdToQuoteIdInterface;
use Psr\Log\LoggerInterface;

/**
 * Has a cart recognised before its placement runs, when a proof is sent with the request, and forgets the record again
 * when the placement fails.
 *
 * What may become a test cart is {@see TestCartRecognitionInterface}'s to decide. "Made" a record means the cart was
 * not recorded before recognition ran, which is what lets calls nest: the inner call finds the outer call's record, and
 * a failure forgets it once, in the outer call.
 */
class TestCartPlacement implements TestCartPlacementInterface
{
    /**
     * @param RequestProofInterface $requestProof Tells whether a proof was sent with the request being served
     * @param TestCartRecognitionInterface $recognition Records the cart as a test cart, or refuses
     * @param TestCartsInterface $testCarts Tells whether the cart was already recorded when the placement started
     * @param TestCartRecordsInterface $records Forgets the record a failed placement made
     * @param MaskedQuoteIdToQuoteIdInterface $quoteIds Resolves a cart addressed by its masked id
     * @param LoggerInterface $logger Where a record that could not be forgotten is reported
     */
    public function __construct(
        private readonly RequestProofInterface $requestProof,
        private readonly TestCartRecognitionInterface $recognition,
        private readonly TestCartsInterface $testCarts,
        private readonly TestCartRecordsInterface $records,
        private readonly MaskedQuoteIdToQuoteIdInterface $quoteIds,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @inheritDoc
     */
    public function place(int|string $cartId, callable $placement, ?string $paymentMethod = null): mixed
    {
        if (!$this->requestProof->isSent()) {
            return $placement();
        }

        $quoteId = $this->quoteId($cartId);
        $wasRecorded = $this->testCarts->isTestCart($quoteId);
        $this->recognition->recognise($quoteId, $paymentMethod);

        try {
            return $placement();
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
     * The entity id of a cart addressed by either of its ids.
     *
     * @param int|string $cartId The cart's entity id, or its masked id
     * @return int Cart (quote) entity id
     * @throws \Magento\Framework\Exception\NoSuchEntityException When no cart has this masked id
     */
    private function quoteId(int|string $cartId): int
    {
        $id = (string)$cartId;

        return ctype_digit($id) ? (int)$id : $this->quoteIds->execute($id);
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
