<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\TestOrder\Test\Integration\Model\ProofSource;

use Hryvinskyi\TestOrder\Model\ProofSource\RequestProofSource;
use Laminas\Stdlib\Parameters;
use Magento\Framework\Webapi\Rest\Request;
use Magento\TestFramework\Helper\Bootstrap;
use PHPUnit\Framework\TestCase;

/**
 * The proof read from a real request object, filled the way the web server fills it. The request drops an empty header
 * when it builds its headers from the server parameters, so what arrives is read in both places.
 *
 * It is the REST area's request: the container hands the test framework's own request out for the plain HTTP request,
 * and that one does not build headers from the server parameters.
 */
class RequestProofSourceTest extends TestCase
{
    private const string SERVER_PARAMETER = 'HTTP_X_TEST_ORDER_PROOF';

    /**
     * The value as sent is handed over: an empty value is a value, and a runner's bug must not pass for no proof.
     *
     * @dataProvider sentValues
     * @param string $value Header value as the web server hands it over
     * @return void
     */
    public function testTheValueSentIsHandedOver(string $value): void
    {
        self::assertSame($value, $this->sourceServing([self::SERVER_PARAMETER => $value])->current());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function sentValues(): array
    {
        return [
            'a proof' => ['1790000000.' . str_repeat('ab', 32)],
            'empty' => [''],
        ];
    }

    /**
     * A request that does not carry the header carries no proof.
     *
     * @return void
     */
    public function testARequestWithoutTheHeaderCarriesNoProof(): void
    {
        self::assertNull($this->sourceServing(['HTTP_ACCEPT' => 'application/json'])->current());
    }

    /**
     * The source over a real request with these server parameters.
     *
     * @param array<string, string> $server Server parameters
     * @return RequestProofSource
     */
    private function sourceServing(array $server): RequestProofSource
    {
        $objectManager = Bootstrap::getObjectManager();
        $request = $objectManager->create(Request::class);
        self::assertInstanceOf(Request::class, $request);
        $request->setServer(new Parameters($server));
        $source = $objectManager->create(RequestProofSource::class, ['request' => $request]);
        self::assertInstanceOf(RequestProofSource::class, $source);

        return $source;
    }
}
