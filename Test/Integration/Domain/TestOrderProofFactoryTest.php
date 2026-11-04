<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\TestOrder\Test\Integration\Domain;

use Hryvinskyi\TestOrder\Domain\MalformedTestOrderProofException;
use Hryvinskyi\TestOrder\Domain\TestOrderProofFactory;
use Magento\TestFramework\Helper\Bootstrap;
use PHPUnit\Framework\TestCase;

/**
 * The proof is made by the factory Magento generates for it, and a malformed value reaches the caller as the
 * proof's own refusal, not as a container error.
 *
 * @magentoAppArea global
 */
class TestOrderProofFactoryTest extends TestCase
{
    /**
     * A well-formed value becomes a proof.
     *
     * @return void
     */
    public function testTheGeneratedFactoryMakesAProof(): void
    {
        $signature = str_repeat('0f', 32);

        $proof = $this->factory()->create(['value' => '1790000000.' . $signature]);

        self::assertSame(1790000000, $proof->issuedAt());
        self::assertSame($signature, $proof->signature());
    }

    /**
     * A malformed value is refused by the proof itself.
     *
     * @return void
     */
    public function testAMalformedValueIsRefusedThroughTheFactory(): void
    {
        $this->expectException(MalformedTestOrderProofException::class);

        $this->factory()->create(['value' => 'not-a-proof']);
    }

    /**
     * @return TestOrderProofFactory
     */
    private function factory(): TestOrderProofFactory
    {
        $factory = Bootstrap::getObjectManager()->get(TestOrderProofFactory::class);
        self::assertInstanceOf(TestOrderProofFactory::class, $factory);

        return $factory;
    }
}
