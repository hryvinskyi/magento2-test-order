<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\TestOrder\Model;

use Hryvinskyi\TestOrder\Api\ProofSourceInterface;
use Hryvinskyi\TestOrder\Api\ProofVerifierInterface;
use Hryvinskyi\TestOrder\Api\RequestProofInterface;
use Hryvinskyi\TestOrder\Domain\MalformedTestOrderProofException;
use Hryvinskyi\TestOrder\Domain\TestOrderAction;
use Hryvinskyi\TestOrder\Domain\TestOrderProofFactory;
use Magento\Framework\Exception\AuthorizationException;
use Magento\Framework\Stdlib\DateTime\DateTime;

/**
 * Judges the proof of the request being served: read from the proof source, parsed, and verified against the shop's
 * secret at the current UTC time. A malformed proof is simply not valid.
 */
class RequestProof implements RequestProofInterface
{
    /**
     * @param ProofSourceInterface $proofSource The proof sent with the request being served
     * @param TestOrderProofFactory $proofFactory Parses the proof
     * @param ProofVerifierInterface $proofVerifier Checks the proof against the shop's secret
     * @param DateTime $clock Current UTC time
     */
    public function __construct(
        private readonly ProofSourceInterface $proofSource,
        private readonly TestOrderProofFactory $proofFactory,
        private readonly ProofVerifierInterface $proofVerifier,
        private readonly DateTime $clock
    ) {
    }

    /**
     * @inheritDoc
     */
    public function isSent(): bool
    {
        return $this->proofSource->current() !== null;
    }

    /**
     * @inheritDoc
     */
    public function isValidFor(TestOrderAction $action, string $subject): bool
    {
        $rawProof = $this->proofSource->current();
        if ($rawProof === null) {
            return false;
        }

        try {
            $proof = $this->proofFactory->create(['value' => $rawProof]);
        } catch (MalformedTestOrderProofException) {
            return false;
        }

        return $this->proofVerifier->verify($proof, $action, $subject, $this->clock->gmtTimestamp());
    }

    /**
     * @inheritDoc
     */
    public function authorise(TestOrderAction $action, string $subject): void
    {
        if (!$this->isValidFor($action, $subject)) {
            throw new AuthorizationException(
                __('The test-order proof sent with this request could not be verified.')
            );
        }
    }
}
