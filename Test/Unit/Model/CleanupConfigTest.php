<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\TestOrder\Test\Unit\Model;

use Hryvinskyi\TestOrder\Model\CleanupConfig;
use Magento\Framework\App\Config\ScopeConfigInterface;
use PHPUnit\Framework\TestCase;

/**
 * The clean-up settings as stored, with a usable number of minutes whatever was typed.
 */
class CleanupConfigTest extends TestCase
{
    /**
     * Deleting cancelled test orders follows the flag.
     *
     * @return void
     */
    public function testDeletingFollowsTheFlag(): void
    {
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('isSetFlag')->with(CleanupConfig::XML_PATH_DELETE_CANCELLED)->willReturn(true);

        self::assertTrue((new CleanupConfig($scopeConfig))->deletesCancelledTestOrders());
    }

    /**
     * A stored number of minutes is used as it is; anything below one minute or not a number gives the default.
     *
     * @dataProvider storedMinutes
     * @param mixed $stored Value as stored
     * @param int $expected Minutes used
     * @return void
     */
    public function testTheMinutesAreUsableWhateverWasStored(mixed $stored, int $expected): void
    {
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->with(CleanupConfig::XML_PATH_ABANDON_AFTER_MINUTES)->willReturn($stored);

        self::assertSame($expected, (new CleanupConfig($scopeConfig))->abandonAfterMinutes());
    }

    /**
     * @return array<string, array{mixed, int}>
     */
    public static function storedMinutes(): array
    {
        return [
            'a number' => ['45', 45],
            'one minute' => ['1', 1],
            'nothing stored' => [null, 30],
            'empty' => ['', 30],
            'zero' => ['0', 30],
            'negative' => ['-5', 30],
            'not a number' => ['half an hour', 30],
        ];
    }
}
