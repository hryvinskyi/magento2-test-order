<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\TestOrder\Test\Unit\Model;

use Hryvinskyi\TestOrder\Model\DeploymentConfigLivePaymentsDeclaration;
use Magento\Framework\App\DeploymentConfig;
use PHPUnit\Framework\TestCase;

/**
 * Only the boolean true in app/etc/env.php declares that test orders may run on live payments; anything else, absent
 * or mistyped, does not.
 */
class DeploymentConfigLivePaymentsDeclarationTest extends TestCase
{
    /**
     * @dataProvider values
     * @param mixed $value Value at test_order/live_payments
     * @param bool $allows Whether it declares test orders allowed on live payments
     * @return void
     */
    public function testOnlyTheBooleanTrueDeclaresIt(mixed $value, bool $allows): void
    {
        $deploymentConfig = $this->createMock(DeploymentConfig::class);
        $deploymentConfig->method('get')->with('test_order/live_payments')->willReturn($value);

        self::assertSame(
            $allows,
            (new DeploymentConfigLivePaymentsDeclaration($deploymentConfig))->allowsTestOrdersOnLivePayments()
        );
    }

    /**
     * @return array<string, array{mixed, bool}>
     */
    public static function values(): array
    {
        return [
            'true' => [true, true],
            'absent' => [null, false],
            'false' => [false, false],
            'the string 1' => ['1', false],
            'the string true' => ['true', false],
            'the number 1' => [1, false],
        ];
    }
}
