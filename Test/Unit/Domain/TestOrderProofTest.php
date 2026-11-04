<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\TestOrder\Test\Unit\Domain;

use Hryvinskyi\TestOrder\Domain\MalformedTestOrderProofException;
use Hryvinskyi\TestOrder\Domain\TestOrderAction;
use Hryvinskyi\TestOrder\Domain\TestOrderProof;
use PHPUnit\Framework\TestCase;

/**
 * The proof is accepted only in its exact shape, binds a subject to its time, is fresh for five minutes either side of
 * that time, and can be subclassed by the framework's generated code.
 */
class TestOrderProofTest extends TestCase
{
    private const string SIGNATURE = '0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef';

    /**
     * The framework generates interceptors and proxies as plain subclasses, so the proof must allow one.
     *
     * @return void
     */
    public function testAGeneratedSubclassCanStandInForTheProof(): void
    {
        $proof = new class ('1790000000.' . self::SIGNATURE) extends TestOrderProof {
        };

        self::assertSame(1790000000, $proof->issuedAt());
        self::assertSame(self::SIGNATURE, $proof->signature());
    }

    /**
     * A well-formed proof yields its time and its signature unchanged.
     *
     * @return void
     */
    public function testAWellFormedProofYieldsItsTimeAndSignature(): void
    {
        $proof = new TestOrderProof('1790000000.' . self::SIGNATURE);

        self::assertSame(1790000000, $proof->issuedAt());
        self::assertSame(self::SIGNATURE, $proof->signature());
    }

    /**
     * Anything but the exact shape is refused, without being trimmed, decoded or lower-cased first.
     *
     * @dataProvider malformedValues
     * @param string $value Value sent as a proof
     * @return void
     */
    public function testAValueWithoutTheProofsShapeIsRefused(string $value): void
    {
        $this->expectException(MalformedTestOrderProofException::class);

        new TestOrderProof($value);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function malformedValues(): array
    {
        return [
            'empty' => [''],
            'no dot' => ['1790000000' . self::SIGNATURE],
            'nine digits of time' => ['179000000.' . self::SIGNATURE],
            'eleven digits of time' => ['17900000000.' . self::SIGNATURE],
            'leading zero in the time' => ['0790000000.' . self::SIGNATURE],
            'negative time' => ['-790000000.' . self::SIGNATURE],
            'uppercase signature' => ['1790000000.' . strtoupper(self::SIGNATURE)],
            'short signature' => ['1790000000.' . substr(self::SIGNATURE, 1)],
            'long signature' => ['1790000000.' . self::SIGNATURE . '0'],
            'non-hex signature' => ['1790000000.' . substr(self::SIGNATURE, 1) . 'g'],
            'leading space' => [' 1790000000.' . self::SIGNATURE],
            'trailing newline' => ['1790000000.' . self::SIGNATURE . "\n"],
            'base64 signature' => ['1790000000.' . base64_encode(str_repeat('a', 48))],
        ];
    }

    /**
     * The signed message is the action, the subject and the proof's own time, so a proof made for one action or one
     * cart is not one for another.
     *
     * @return void
     */
    public function testTheSignedMessageIsTheActionTheSubjectAndTheProofsTime(): void
    {
        $proof = new TestOrderProof('1790000000.' . self::SIGNATURE);

        self::assertSame(
            'place|cartMaskedId|1790000000',
            $proof->signedMessage(TestOrderAction::Place, 'cartMaskedId')
        );
        self::assertSame(
            'abandon|cartMaskedId|1790000000',
            $proof->signedMessage(TestOrderAction::Abandon, 'cartMaskedId')
        );
        self::assertSame(
            'recognise|recognition|1790000000',
            $proof->signedMessage(TestOrderAction::Recognise, 'recognition')
        );
    }

    /**
     * Fresh for exactly five minutes before and after the time it names, and not a second more.
     *
     * @dataProvider freshness
     * @param int $offset Seconds from the proof's time to now
     * @param bool $fresh Whether the proof is fresh then
     * @return void
     */
    public function testAProofIsFreshForFiveMinutesEitherSide(int $offset, bool $fresh): void
    {
        $proof = new TestOrderProof('1790000000.' . self::SIGNATURE);

        self::assertSame($fresh, $proof->isFreshAt(1790000000 + $offset));
    }

    /**
     * @return array<string, array{0: int, 1: bool}>
     */
    public static function freshness(): array
    {
        return [
            'the same second' => [0, true],
            'five minutes later' => [300, true],
            'a second too late' => [301, false],
            'five minutes earlier' => [-300, true],
            'a second too early' => [-301, false],
            'a day later' => [86400, false],
        ];
    }
}
