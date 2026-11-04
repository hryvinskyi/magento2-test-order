<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\TestOrder\Test\Integration\Model;

use Hryvinskyi\TestOrder\Api\KeptTestOrderAnswersInterface;
use Hryvinskyi\TestOrder\Api\TestCartRecordsInterface;
use Hryvinskyi\TestOrder\Api\TestCartsInterface;
use Hryvinskyi\TestOrder\Api\TestOrderRecordChangesInterface;
use Hryvinskyi\TestOrder\Model\TestCarts;
use Hryvinskyi\TestOrder\Model\TestOrderMemo;
use Magento\TestFramework\Helper\Bootstrap;
use PHPUnit\Framework\TestCase;

/**
 * The question asked about a cart before its order exists, answered from the real records through the container's
 * wiring.
 *
 * @magentoDbIsolation enabled
 * @magentoAppIsolation enabled
 * @magentoAppArea global
 */
class TestCartsTest extends TestCase
{
    private const int QUOTE_ID = 987151;

    private const int OTHER_QUOTE_ID = 987152;

    /**
     * The container hands out this module's answer.
     *
     * @return void
     */
    public function testTheContainerHandsOutTheAnswer(): void
    {
        self::assertInstanceOf(TestCarts::class, $this->testCarts());
    }

    /**
     * A recognised cart is a test cart; another cart and a non-positive id are not.
     *
     * @return void
     */
    public function testOnlyARecognisedCartIsATestCart(): void
    {
        $this->records()->recognise(self::QUOTE_ID);

        self::assertTrue($this->testCarts()->isTestCart(self::QUOTE_ID));
        self::assertFalse($this->testCarts()->isTestCart(self::OTHER_QUOTE_ID));
        self::assertFalse($this->testCarts()->isTestCart(0));
    }

    /**
     * An answer kept from earlier in the request does not outlive a recognition.
     *
     * @return void
     */
    public function testRecognitionOverridesAnEarlierAnswerInTheSameRequest(): void
    {
        self::assertFalse($this->testCarts()->isTestCart(self::QUOTE_ID));

        $this->records()->recognise(self::QUOTE_ID);

        self::assertTrue($this->testCarts()->isTestCart(self::QUOTE_ID));
    }

    /**
     * @return TestCartsInterface
     */
    private function testCarts(): TestCartsInterface
    {
        $testCarts = Bootstrap::getObjectManager()->get(TestCartsInterface::class);
        self::assertInstanceOf(TestCartsInterface::class, $testCarts);

        return $testCarts;
    }

    /**
     * Whoever asks and the record writer reach one shared memory through their two ports, so a cart asked about and
     * recognised in one request turns from no into yes at once. The memory is told through the writer's own port, as
     * the repository does, and read through the other's.
     *
     * @return void
     */
    public function testBothPortsOfTheKeptAnswersAreOneSharedInstance(): void
    {
        $objectManager = Bootstrap::getObjectManager();
        $answers = $objectManager->get(KeptTestOrderAnswersInterface::class);
        $changes = $objectManager->get(TestOrderRecordChangesInterface::class);
        self::assertInstanceOf(TestOrderMemo::class, $answers);
        self::assertSame($answers, $changes);

        $answers->rememberQuote(self::QUOTE_ID, false);
        $changes->recognised(self::QUOTE_ID);

        self::assertTrue($answers->forQuote(self::QUOTE_ID));
        self::assertTrue($this->testCarts()->isTestCart(self::QUOTE_ID));
    }

    /**
     * The container's record writer.
     *
     * @return TestCartRecordsInterface
     */
    private function records(): TestCartRecordsInterface
    {
        $records = Bootstrap::getObjectManager()->get(TestCartRecordsInterface::class);
        self::assertInstanceOf(TestCartRecordsInterface::class, $records);

        return $records;
    }
}
