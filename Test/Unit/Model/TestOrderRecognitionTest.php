<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\TestOrder\Test\Unit\Model;

use Hryvinskyi\TestOrder\Api\RecognitionGuardInterface;
use Hryvinskyi\TestOrder\Api\TestOrderRecognitionInterface;
use Hryvinskyi\TestOrder\Domain\TestOrderAction;
use Hryvinskyi\TestOrder\Model\TestOrderRecognition;
use Hryvinskyi\TestOrder\Test\Unit\Fixture\BuildsRequestProof;
use Magento\Framework\Exception\AuthorizationException;
use Magento\Framework\Phrase;
use Magento\Framework\Webapi\Exception as WebapiException;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;

/**
 * The recognition probe answers true for a valid recognition proof in a store no guard objects to; it refuses any
 * other proof as unauthorised, and a guard's objection as forbidden with the guard's reason.
 */
class TestOrderRecognitionTest extends TestCase
{
    use BuildsRequestProof;

    private const int STORE_ID = 5;

    /**
     * A valid recognition proof is confirmed, and the guards are asked about the store being served.
     *
     * @return void
     */
    public function testAValidRecognitionProofIsConfirmed(): void
    {
        $guard = $this->createMock(RecognitionGuardInterface::class);
        $guard->expects(self::once())->method('refusalFor')->with(self::STORE_ID)->willReturn(null);

        self::assertTrue($this->recognition($this->recognitionProof(), $guard)->confirm());
    }

    /**
     * A proof made for a cart, or for another action, is no recognition proof.
     *
     * @dataProvider otherProofs
     * @param string $proof Proof sent
     * @return void
     */
    public function testAProofMadeForSomethingElseIsRefused(string $proof): void
    {
        $this->expectException(AuthorizationException::class);

        $this->recognition($proof)->confirm();
    }

    /**
     * @return array<string, array{string}>
     */
    public static function otherProofs(): array
    {
        return [
            'made for a cart' => [self::signProof(TestOrderAction::Recognise, 'f1Kq0wzWbG2s8yQkS3n1Xr7cV5mT9pLd')],
            'made for placing' => [self::signProof(TestOrderAction::Place, TestOrderRecognitionInterface::SUBJECT)],
        ];
    }

    /**
     * A probe without a proof is refused, and no guard is asked: a caller without a proof learns nothing about the
     * store.
     *
     * @return void
     */
    public function testAProbeWithoutAProofIsRefusedWithoutAskingTheGuards(): void
    {
        $guard = $this->createMock(RecognitionGuardInterface::class);
        $guard->expects(self::never())->method('refusalFor');

        $this->expectException(AuthorizationException::class);

        $this->recognition(null, $guard)->confirm();
    }

    /**
     * While the shop has no secret even a correctly made proof is refused, so the runner learns before it places
     * anything that its orders would be ordinary ones.
     *
     * @return void
     */
    public function testWithoutASecretTheProbeIsRefused(): void
    {
        $this->expectException(AuthorizationException::class);

        $this->recognition($this->recognitionProof(), secret: null)->confirm();
    }

    /**
     * A guard that objects in the store forbids the probe with its own reason.
     *
     * @return void
     */
    public function testAGuardsRefusalForbidsTheProbeWithItsReason(): void
    {
        $guard = $this->createMock(RecognitionGuardInterface::class);
        $guard->method('refusalFor')->willReturn(new Phrase('Payments here run on live keys.'));

        try {
            $this->recognition($this->recognitionProof(), $guard)->confirm();
            self::fail('A probe a guard objects to was confirmed.');
        } catch (WebapiException $refusal) {
            self::assertSame(WebapiException::HTTP_FORBIDDEN, $refusal->getHttpCode());
            self::assertSame('Payments here run on live keys.', $refusal->getMessage());
        }
    }

    /**
     * A recognition proof as the runner makes it.
     *
     * @return string
     */
    private function recognitionProof(): string
    {
        return self::signProof(TestOrderAction::Recognise, TestOrderRecognitionInterface::SUBJECT);
    }

    /**
     * The probe in store {@see self::STORE_ID}.
     *
     * @param string|null $proof Proof sent; null for none
     * @param RecognitionGuardInterface|null $guard The guards; none objecting when null
     * @param string|null $secret The shop's secret; null when it has none
     * @return TestOrderRecognition
     */
    private function recognition(
        ?string $proof,
        ?RecognitionGuardInterface $guard = null,
        ?string $secret = self::PROOF_SECRET
    ): TestOrderRecognition {
        if ($guard === null) {
            $guard = $this->createMock(RecognitionGuardInterface::class);
            $guard->method('refusalFor')->willReturn(null);
        }

        $store = $this->createMock(StoreInterface::class);
        $store->method('getId')->willReturn(self::STORE_ID);
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);

        return new TestOrderRecognition($this->requestProof($proof, $secret), $guard, $storeManager);
    }
}
