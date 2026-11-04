<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\TestOrder\Test\Unit\Model;

use Hryvinskyi\TestOrder\Api\SecretProviderInterface;
use Hryvinskyi\TestOrder\Domain\TestOrderAction;
use Hryvinskyi\TestOrder\Domain\TestOrderProof;
use Hryvinskyi\TestOrder\Model\TestOrderProofVerifier;
use PHPUnit\Framework\TestCase;

/**
 * Only a proof made with the shop's secret, for the same action and subject, within five minutes of now is accepted;
 * with no usable secret nothing is.
 */
class TestOrderProofVerifierTest extends TestCase
{
    private const string SECRET = 'a-test-order-secret-of-forty-characters!';

    private const string SUBJECT = 'f1Kq0wzWbG2s8yQkS3n1Xr7cV5mT9pLd';

    private const int NOW = 1790000000;

    private const TestOrderAction ACTION = TestOrderAction::Place;

    /**
     * A proof the runner made a moment ago for this cart is accepted.
     *
     * @return void
     */
    public function testAProofMadeWithTheSecretForTheSubjectIsAccepted(): void
    {
        $proof = $this->proof(self::SECRET, self::SUBJECT, self::NOW - 30);

        self::assertTrue($this->verifier(self::SECRET)->verify($proof, self::ACTION, self::SUBJECT, self::NOW));
    }

    /**
     * The contract with the end-to-end runner, pinned by one known answer.
     *
     * The proof below is a literal made with PHP's `hash_hmac()`, not computed here, and the runner signs these same
     * literals in its own suite: a change to the signed message, the encoding or the format on either side goes red
     * on that side.
     *
     * @return void
     */
    public function testTheKnownAnswerTheRunnerAlsoSignsIsAccepted(): void
    {
        $proof = new TestOrderProof(
            '1790000000.c8ba3b61b02867cb9452aa6cc895249304830e7214ff8e2c16baa92e427d205b'
        );

        self::assertTrue(
            $this->verifier('e2e-proof-spec-secret-0123456789abcdef')->verify(
                $proof,
                TestOrderAction::Place,
                'Mg4w0eZ3k1qYbVt9XcLpR2sNd8HuJf6A',
                1790000000
            )
        );
    }

    /**
     * A signature that differs in a single character is refused.
     *
     * @return void
     */
    public function testATamperedSignatureIsRefused(): void
    {
        $genuine = $this->sign(self::SECRET, self::SUBJECT, self::NOW);
        $tampered = substr($genuine, 0, -1) . ($genuine[63] === '0' ? '1' : '0');

        self::assertFalse(
            $this->verifier(self::SECRET)->verify(
                new TestOrderProof(self::NOW . '.' . $tampered),
                self::ACTION,
                self::SUBJECT,
                self::NOW
            )
        );
    }

    /**
     * A proof made with another secret is refused.
     *
     * @return void
     */
    public function testAProofMadeWithAnotherSecretIsRefused(): void
    {
        $proof = $this->proof('another-test-order-secret-of-forty-chars', self::SUBJECT, self::NOW);

        self::assertFalse($this->verifier(self::SECRET)->verify($proof, self::ACTION, self::SUBJECT, self::NOW));
    }

    /**
     * A proof made for one cart does not prove another, nor the recognition probe.
     *
     * @return void
     */
    public function testAProofMadeForAnotherSubjectIsRefused(): void
    {
        $proof = $this->proof(self::SECRET, self::SUBJECT, self::NOW);

        self::assertFalse(
            $this->verifier(self::SECRET)->verify($proof, self::ACTION, 'zzKq0wzWbG2s8yQkS3n1Xr7cV5mT9pLd', self::NOW)
        );
        self::assertFalse($this->verifier(self::SECRET)->verify($proof, self::ACTION, 'recognition', self::NOW));
    }

    /**
     * A proof made for one action does not allow another on the same subject: a placement proof cannot end an order,
     * and an abandon proof cannot place one.
     *
     * @dataProvider otherActions
     * @param TestOrderAction $signedFor Action the runner signed for
     * @param TestOrderAction $askedFor Action the shop is asked to allow
     * @return void
     */
    public function testAProofMadeForAnotherActionIsRefused(TestOrderAction $signedFor, TestOrderAction $askedFor): void
    {
        $proof = $this->proof(self::SECRET, self::SUBJECT, self::NOW, $signedFor);

        self::assertTrue($this->verifier(self::SECRET)->verify($proof, $signedFor, self::SUBJECT, self::NOW));
        self::assertFalse($this->verifier(self::SECRET)->verify($proof, $askedFor, self::SUBJECT, self::NOW));
    }

    /**
     * @return array<string, array{TestOrderAction, TestOrderAction}>
     */
    public static function otherActions(): array
    {
        return [
            'placement proof to abandon' => [TestOrderAction::Place, TestOrderAction::Abandon],
            'abandon proof to place' => [TestOrderAction::Abandon, TestOrderAction::Place],
            'probe proof to place' => [TestOrderAction::Recognise, TestOrderAction::Place],
        ];
    }

    /**
     * A genuine proof older or newer than five minutes is refused.
     *
     * @dataProvider staleTimes
     * @param int $issuedAt Time the proof names
     * @return void
     */
    public function testAGenuineProofOutsideTheFreshnessWindowIsRefused(int $issuedAt): void
    {
        $proof = $this->proof(self::SECRET, self::SUBJECT, $issuedAt);

        self::assertFalse($this->verifier(self::SECRET)->verify($proof, self::ACTION, self::SUBJECT, self::NOW));
    }

    /**
     * @return array<string, array{0: int}>
     */
    public static function staleTimes(): array
    {
        return [
            'made a second too long ago' => [self::NOW - 301],
            'made for a second too far ahead' => [self::NOW + 301],
        ];
    }

    /**
     * A signature over the subject with a different time than the one the proof names is refused.
     *
     * @return void
     */
    public function testASignatureOverAnotherTimeIsRefused(): void
    {
        $signedForEarlier = $this->sign(self::SECRET, self::SUBJECT, self::NOW - 60);

        self::assertFalse(
            $this->verifier(self::SECRET)->verify(
                new TestOrderProof(self::NOW . '.' . $signedForEarlier),
                self::ACTION,
                self::SUBJECT,
                self::NOW
            )
        );
    }

    /**
     * Without a configured secret the shop recognises nothing, not even a proof made with an empty key.
     *
     * @return void
     */
    public function testWithoutASecretNothingIsAccepted(): void
    {
        self::assertFalse(
            $this->verifier(null)->verify(
                $this->proof('', self::SUBJECT, self::NOW),
                self::ACTION,
                self::SUBJECT,
                self::NOW
            )
        );
    }

    /**
     * A secret shorter than the minimum is no secret, even when the proof was made with it.
     *
     * @return void
     */
    public function testAShortSecretAcceptsNothing(): void
    {
        $short = str_repeat('s', SecretProviderInterface::MINIMUM_LENGTH - 1);

        self::assertFalse(
            $this->verifier($short)->verify(
                $this->proof($short, self::SUBJECT, self::NOW),
                self::ACTION,
                self::SUBJECT,
                self::NOW
            )
        );
    }

    /**
     * A secret of exactly the minimum length is usable.
     *
     * @return void
     */
    public function testASecretOfTheMinimumLengthIsUsable(): void
    {
        $minimal = str_repeat('s', SecretProviderInterface::MINIMUM_LENGTH);

        $proof = $this->proof($minimal, self::SUBJECT, self::NOW);

        self::assertTrue($this->verifier($minimal)->verify($proof, self::ACTION, self::SUBJECT, self::NOW));
    }

    /**
     * An empty subject proves nothing, even with a signature over it.
     *
     * @return void
     */
    public function testAnEmptySubjectIsRefused(): void
    {
        self::assertFalse(
            $this->verifier(self::SECRET)->verify(
                $this->proof(self::SECRET, '', self::NOW),
                self::ACTION,
                '',
                self::NOW
            )
        );
    }

    /**
     * A verifier over a fixed secret.
     *
     * @param string|null $secret Secret the shop has
     * @return TestOrderProofVerifier
     */
    private function verifier(?string $secret): TestOrderProofVerifier
    {
        $secretProvider = $this->createStub(SecretProviderInterface::class);
        $secretProvider->method('secret')->willReturn($secret);

        return new TestOrderProofVerifier($secretProvider);
    }

    /**
     * A proof as the runner makes it.
     *
     * @param string $secret Runner's secret
     * @param string $subject Subject it signs
     * @param int $issuedAt Time it names
     * @param TestOrderAction $action Action it signs for
     * @return TestOrderProof
     */
    private function proof(
        string $secret,
        string $subject,
        int $issuedAt,
        TestOrderAction $action = self::ACTION
    ): TestOrderProof {
        return new TestOrderProof($issuedAt . '.' . $this->sign($secret, $subject, $issuedAt, $action));
    }

    /**
     * The runner's signature, computed independently of the class under test.
     *
     * @param string $secret Runner's secret
     * @param string $subject Subject it signs
     * @param int $issuedAt Time it names
     * @param TestOrderAction $action Action it signs for
     * @return string
     */
    private function sign(
        string $secret,
        string $subject,
        int $issuedAt,
        TestOrderAction $action = self::ACTION
    ): string {
        return hash_hmac('sha256', $action->value . '|' . $subject . '|' . $issuedAt, $secret);
    }
}
