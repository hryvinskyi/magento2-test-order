<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\TestOrder\Model;

use Hryvinskyi\TestOrder\Api\AbandonTestOrderInterface;
use Hryvinskyi\TestOrder\Api\Data\AbandonResultInterface;
use Hryvinskyi\TestOrder\Api\RequestProofInterface;
use Hryvinskyi\TestOrder\Api\TestOrderAbandonmentInterface;
use Hryvinskyi\TestOrder\Domain\TestOrderAction;
use Magento\Quote\Model\MaskedQuoteIdToQuoteIdInterface;

/**
 * The REST surface for ending a test order: checks the runner's proof over the masked cart id, then hands the cart to
 * the abandon use case.
 *
 * The proof is checked before the cart is even looked up, so a caller without one learns nothing, not even whether
 * the cart exists.
 */
class TestOrderAbandonment implements TestOrderAbandonmentInterface
{
    /**
     * @param RequestProofInterface $requestProof The proof sent with the request
     * @param MaskedQuoteIdToQuoteIdInterface $quoteIds Resolves the guest cart's masked id
     * @param AbandonTestOrderInterface $abandonTestOrder Ends the cart's test order
     */
    public function __construct(
        private readonly RequestProofInterface $requestProof,
        private readonly MaskedQuoteIdToQuoteIdInterface $quoteIds,
        private readonly AbandonTestOrderInterface $abandonTestOrder
    ) {
    }

    /**
     * @inheritDoc
     */
    public function abandon(string $cartId): AbandonResultInterface
    {
        $this->requestProof->authorise(TestOrderAction::Abandon, $cartId);

        return $this->abandonTestOrder->abandon($this->quoteIds->execute($cartId));
    }
}
