<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\TestOrder\Test\Unit\Fixture;

use Hryvinskyi\TestOrder\Api\ProofSourceInterface;
use Hryvinskyi\TestOrder\Api\SecretProviderInterface;
use Hryvinskyi\TestOrder\Domain\TestOrderAction;
use Hryvinskyi\TestOrder\Domain\TestOrderProof;
use Hryvinskyi\TestOrder\Domain\TestOrderProofFactory;
use Hryvinskyi\TestOrder\Model\RequestProof;
use Hryvinskyi\TestOrder\Model\TestOrderProofVerifier;
use Magento\Framework\Stdlib\DateTime\DateTime;

/**
 * The real judging of a request's proof — parsing, the verifier and the clock — around a request that sends a given
 * proof to a shop with a given secret, at a fixed time. Used by test cases only.
 */
trait BuildsRequestProof
{
    /**
     * A secret long enough for the shop to use.
     */
    private const string PROOF_SECRET = 'a-test-order-secret-of-forty-characters!';

    /**
     * The fixed current time, unix seconds.
     */
    private const int PROOF_NOW = 1790000000;

    /**
     * The request's proof, judged for real.
     *
     * @param string|null $proof Proof the request sends; null when it sends none
     * @param string|null $secret The shop's secret; null when it has none
     * @return RequestProof
     */
    private function requestProof(?string $proof, ?string $secret = self::PROOF_SECRET): RequestProof
    {
        $proofSource = $this->createMock(ProofSourceInterface::class);
        $proofSource->method('current')->willReturn($proof);

        $proofFactory = $this->getMockBuilder(TestOrderProofFactory::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['create'])
            ->getMock();
        $proofFactory->method('create')->willReturnCallback(
            static fn (array $data): TestOrderProof => new TestOrderProof(
                is_string($data['value'] ?? null) ? $data['value'] : ''
            )
        );

        $secretProvider = $this->createMock(SecretProviderInterface::class);
        $secretProvider->method('secret')->willReturn($secret);

        $clock = $this->createMock(DateTime::class);
        $clock->method('gmtTimestamp')->willReturn(self::PROOF_NOW);

        return new RequestProof($proofSource, $proofFactory, new TestOrderProofVerifier($secretProvider), $clock);
    }

    /**
     * A proof as the end-to-end runner makes it: an HMAC over `<action>|<subject>|<unix-seconds>`, written out here
     * independently of the class under test.
     *
     * @param TestOrderAction $action Action signed for
     * @param string $subject Subject signed over
     * @param string $secret Secret signed with
     * @param int $issuedAt Unix seconds the proof names
     * @return string
     */
    private static function signProof(
        TestOrderAction $action,
        string $subject,
        string $secret = self::PROOF_SECRET,
        int $issuedAt = self::PROOF_NOW
    ): string {
        return $issuedAt . '.' . hash_hmac('sha256', $action->value . '|' . $subject . '|' . $issuedAt, $secret);
    }
}
