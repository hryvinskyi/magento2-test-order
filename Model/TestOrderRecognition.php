<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\TestOrder\Model;

use Hryvinskyi\TestOrder\Api\RecognitionGuardInterface;
use Hryvinskyi\TestOrder\Api\RequestProofInterface;
use Hryvinskyi\TestOrder\Api\TestOrderRecognitionInterface;
use Hryvinskyi\TestOrder\Domain\TestOrderAction;
use Magento\Framework\Webapi\Exception as WebapiException;
use Magento\Store\Model\StoreManagerInterface;

/**
 * The REST surface a runner asks before placing anything: answers true for a valid recognition proof in a store whose
 * guards raise no objection, and refuses every other request.
 *
 * The proof is checked first, so a caller without one learns nothing about the store, not even whether test orders
 * would be refused there.
 */
class TestOrderRecognition implements TestOrderRecognitionInterface
{
    /**
     * @param RequestProofInterface $requestProof The proof sent with the request
     * @param RecognitionGuardInterface $recognitionGuard Every guard that may veto test orders in a store
     * @param StoreManagerInterface $storeManager The store being served
     */
    public function __construct(
        private readonly RequestProofInterface $requestProof,
        private readonly RecognitionGuardInterface $recognitionGuard,
        private readonly StoreManagerInterface $storeManager
    ) {
    }

    /**
     * @inheritDoc
     */
    public function confirm(): bool
    {
        $this->requestProof->authorise(TestOrderAction::Recognise, self::SUBJECT);

        $refusal = $this->recognitionGuard->refusalFor((int)$this->storeManager->getStore()->getId());
        if ($refusal !== null) {
            throw new WebapiException($refusal, 0, WebapiException::HTTP_FORBIDDEN);
        }

        return true;
    }
}
