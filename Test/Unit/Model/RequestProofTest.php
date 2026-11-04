<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\TestOrder\Test\Unit\Model;

use Hryvinskyi\TestOrder\Domain\TestOrderAction;
use Hryvinskyi\TestOrder\Domain\TestOrderProof;
use Hryvinskyi\TestOrder\Test\Unit\Fixture\BuildsRequestProof;
use Magento\Framework\Exception\AuthorizationException;
use PHPUnit\Framework\TestCase;

/**
 * A request's proof is sent or not, and valid only when well formed, fresh and made with the shop's secret over the
 * subject; anything else is refused with an authorisation error.
 */
class RequestProofTest extends TestCase
{
    use BuildsRequestProof;

    private const string SUBJECT = 'f1Kq0wzWbG2s8yQkS3n1Xr7cV5mT9pLd';

    /**
     * A request without the header sends no proof, and has no valid one.
     *
     * @return void
     */
    public function testARequestWithoutAProofSendsNone(): void
    {
        $requestProof = $this->requestProof(null);

        self::assertFalse($requestProof->isSent());
        self::assertFalse($requestProof->isValidFor(TestOrderAction::Place, self::SUBJECT));
    }

    /**
     * An empty header is a proof sent, and not a valid one.
     *
     * @return void
     */
    public function testAnEmptyHeaderIsSentButNotValid(): void
    {
        $requestProof = $this->requestProof('');

        self::assertTrue($requestProof->isSent());
        self::assertFalse($requestProof->isValidFor(TestOrderAction::Place, self::SUBJECT));
    }

    /**
     * A fresh proof made with the secret for an action over the subject is valid for that action on that subject only,
     * and authorises it.
     *
     * @return void
     */
    public function testAValidProofAuthorisesItsSubjectOnly(): void
    {
        $requestProof = $this->requestProof(self::signProof(TestOrderAction::Place, self::SUBJECT));

        self::assertTrue($requestProof->isValidFor(TestOrderAction::Place, self::SUBJECT));
        self::assertFalse($requestProof->isValidFor(TestOrderAction::Place, 'another-subject'));
        self::assertFalse($requestProof->isValidFor(TestOrderAction::Abandon, self::SUBJECT));
        $requestProof->authorise(TestOrderAction::Place, self::SUBJECT);
    }

    /**
     * Every proof that cannot be verified is refused with an authorisation error.
     *
     * @dataProvider unverifiableProofs
     * @param string|null $proof Proof sent
     * @param string|null $secret The shop's secret
     * @return void
     */
    public function testAProofThatCannotBeVerifiedIsRefused(?string $proof, ?string $secret): void
    {
        $this->expectException(AuthorizationException::class);
        $this->expectExceptionMessage('The test-order proof sent with this request could not be verified.');

        $this->requestProof($proof, $secret)->authorise(TestOrderAction::Place, self::SUBJECT);
    }

    /**
     * @return array<string, array{string|null, string|null}>
     */
    public static function unverifiableProofs(): array
    {
        return [
            'none sent' => [null, self::PROOF_SECRET],
            'malformed' => ['not-a-proof', self::PROOF_SECRET],
            'made with another secret' => [
                self::signProof(TestOrderAction::Place, self::SUBJECT, 'another-test-order-secret-of-forty-chars'),
                self::PROOF_SECRET,
            ],
            'made for another action' => [
                self::signProof(TestOrderAction::Abandon, self::SUBJECT),
                self::PROOF_SECRET,
            ],
            'stale' => [
                self::signProof(
                    TestOrderAction::Place,
                    self::SUBJECT,
                    issuedAt: self::PROOF_NOW - TestOrderProof::FRESHNESS_SECONDS - 1
                ),
                self::PROOF_SECRET,
            ],
            'the shop has no secret' => [self::signProof(TestOrderAction::Place, self::SUBJECT), null],
        ];
    }
}
