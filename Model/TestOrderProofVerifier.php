<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\TestOrder\Model;

use Hryvinskyi\TestOrder\Api\ProofVerifierInterface;
use Hryvinskyi\TestOrder\Api\SecretProviderInterface;
use Hryvinskyi\TestOrder\Domain\TestOrderAction;
use Hryvinskyi\TestOrder\Domain\TestOrderProof;

/**
 * Checks a proof against the shop's test-order secret.
 *
 * The time is the caller's, never read here, so the decision depends only on its arguments and the secret. The
 * signatures are compared with {@see hash_equals()}, whose time does not depend on where they first differ.
 */
class TestOrderProofVerifier implements ProofVerifierInterface
{
    private const string ALGORITHM = 'sha256';

    /**
     * @param SecretProviderInterface $secretProvider The shop's test-order secret
     */
    public function __construct(
        private readonly SecretProviderInterface $secretProvider
    ) {
    }

    /**
     * @inheritDoc
     */
    public function verify(TestOrderProof $proof, TestOrderAction $action, string $subject, int $now): bool
    {
        $secret = $this->secretProvider->secret();
        if ($secret === null || strlen($secret) < SecretProviderInterface::MINIMUM_LENGTH) {
            return false;
        }

        if ($subject === '' || !$proof->isFreshAt($now)) {
            return false;
        }

        return hash_equals(
            hash_hmac(self::ALGORITHM, $proof->signedMessage($action, $subject), $secret),
            $proof->signature()
        );
    }
}
