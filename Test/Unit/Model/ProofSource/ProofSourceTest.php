<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\TestOrder\Test\Unit\Model\ProofSource;

use Hryvinskyi\TestOrder\Model\ProofSource\NullProofSource;
use Hryvinskyi\TestOrder\Model\ProofSource\RequestProofSource;
use Magento\Framework\App\Request\Http;
use Magento\Framework\App\RequestInterface;
use PHPUnit\Framework\TestCase;

/**
 * A REST or GraphQL request hands over its X-Test-Order-Proof header as sent; anywhere else there is no proof.
 */
class ProofSourceTest extends TestCase
{
    /**
     * The header's value is handed over unchanged.
     *
     * @dataProvider headerValues
     * @param string $value Header value as sent
     * @return void
     */
    public function testTheHeaderIsHandedOverAsSent(string $value): void
    {
        self::assertSame($value, (new RequestProofSource($this->httpRequest($value)))->current());
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function headerValues(): array
    {
        return [
            'a proof' => ['1790000000.' . str_repeat('ab', 32)],
            'padded' => ['  1790000000.x '],
        ];
    }

    /**
     * A request without the header carries no proof.
     *
     * @return void
     */
    public function testARequestWithoutTheHeaderCarriesNoProof(): void
    {
        self::assertNull((new RequestProofSource($this->httpRequest(false)))->current());
    }

    /**
     * A request that is not an HTTP request has no headers to read.
     *
     * @return void
     */
    public function testARequestWithoutHeadersCarriesNoProof(): void
    {
        self::assertNull((new RequestProofSource($this->createStub(RequestInterface::class)))->current());
    }

    /**
     * Outside REST and GraphQL there is never a proof.
     *
     * @return void
     */
    public function testTheNullSourceNeverHasAProof(): void
    {
        self::assertNull((new NullProofSource())->current());
    }

    /**
     * An HTTP request whose proof header is fixed.
     *
     * @param string|false $value Header value; false when the header is absent
     * @return Http
     */
    private function httpRequest(string|false $value): Http
    {
        $request = $this->createMock(Http::class);
        $request->method('getHeader')
            ->with(RequestProofSource::HEADER)
            ->willReturn($value);

        return $request;
    }
}
