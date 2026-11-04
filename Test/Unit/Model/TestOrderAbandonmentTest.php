<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\TestOrder\Test\Unit\Model;

use Hryvinskyi\TestOrder\Api\AbandonTestOrderInterface;
use Hryvinskyi\TestOrder\Domain\TestOrderAction;
use Hryvinskyi\TestOrder\Model\Data\AbandonResult;
use Hryvinskyi\TestOrder\Model\TestOrderAbandonment;
use Hryvinskyi\TestOrder\Test\Unit\Fixture\BuildsRequestProof;
use Magento\Framework\Exception\AuthorizationException;
use Magento\Quote\Model\MaskedQuoteIdToQuoteIdInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The REST surface hands the cart to the abandon use case only for a valid proof made over the masked cart id; any
 * other request is refused before the cart is even looked up.
 */
class TestOrderAbandonmentTest extends TestCase
{
    use BuildsRequestProof;

    private const string MASKED_ID = 'f1Kq0wzWbG2s8yQkS3n1Xr7cV5mT9pLd';

    private const int QUOTE_ID = 4711;

    /**
     * @var MaskedQuoteIdToQuoteIdInterface&MockObject
     */
    private MaskedQuoteIdToQuoteIdInterface&MockObject $quoteIds;

    /**
     * @var AbandonTestOrderInterface&MockObject
     */
    private AbandonTestOrderInterface&MockObject $abandonTestOrder;

    /**
     * @inheritDoc
     */
    protected function setUp(): void
    {
        $this->quoteIds = $this->createMock(MaskedQuoteIdToQuoteIdInterface::class);
        $this->abandonTestOrder = $this->createMock(AbandonTestOrderInterface::class);
    }

    /**
     * With a valid proof for the cart, its test order is abandoned and the use case's answer handed back.
     *
     * @return void
     */
    public function testAValidProofAbandonsTheCartsTestOrder(): void
    {
        $answer = new AbandonResult('canceled', false, '100000042');
        $this->quoteIds->method('execute')->with(self::MASKED_ID)->willReturn(self::QUOTE_ID);
        $this->abandonTestOrder->expects(self::once())->method('abandon')->with(self::QUOTE_ID)->willReturn($answer);

        self::assertSame($answer, $this->abandonment(self::signProof(TestOrderAction::Abandon, self::MASKED_ID))
            ->abandon(self::MASKED_ID));
    }

    /**
     * A request without a proof, or with one made for another cart or another action, is refused and touches nothing.
     *
     * @dataProvider refusedProofs
     * @param string|null $proof Proof sent
     * @return void
     */
    public function testAnyOtherRequestIsRefusedBeforeTheCartIsLookedUp(?string $proof): void
    {
        $this->quoteIds->expects(self::never())->method('execute');
        $this->abandonTestOrder->expects(self::never())->method('abandon');

        $this->expectException(AuthorizationException::class);

        $this->abandonment($proof)->abandon(self::MASKED_ID);
    }

    /**
     * @return array<string, array{string|null}>
     */
    public static function refusedProofs(): array
    {
        return [
            'no proof' => [null],
            'made for another cart' => [self::signProof(TestOrderAction::Abandon, 'zzKq0wzWbG2s8yQkS3n1Xr7cV5mT9pLd')],
            'made for the recognition probe' => [self::signProof(TestOrderAction::Recognise, 'recognition')],
            'made for placing the cart' => [self::signProof(TestOrderAction::Place, self::MASKED_ID)],
            'made with another secret' => [
                self::signProof(TestOrderAction::Abandon, self::MASKED_ID, 'another-test-order-secret-of-forty-chars'),
            ],
        ];
    }

    /**
     * The REST surface around a request that sends the proof.
     *
     * @param string|null $proof Proof sent; null for none
     * @return TestOrderAbandonment
     */
    private function abandonment(?string $proof): TestOrderAbandonment
    {
        return new TestOrderAbandonment($this->requestProof($proof), $this->quoteIds, $this->abandonTestOrder);
    }
}
