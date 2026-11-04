<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\TestOrder\Test\Unit\Model;

use Hryvinskyi\TestOrder\Model\DeploymentConfigSecretProvider;
use Magento\Framework\App\DeploymentConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The secret comes from app/etc/env.php only; a value there that cannot serve as a secret yields none and says so.
 */
class DeploymentConfigSecretProviderTest extends TestCase
{
    /**
     * A secret of usable length is handed over as configured.
     *
     * @return void
     */
    public function testAUsableSecretIsHandedOverAsConfigured(): void
    {
        $secret = str_repeat('k', 32);
        $logger = $this->logger();
        $logger->expects(self::never())->method('warning');

        self::assertSame($secret, $this->provider($secret, $logger)->secret());
    }

    /**
     * No secret configured: none, and nothing to report.
     *
     * @dataProvider absentValues
     * @param string|null $value Value at test_order/proof_secret
     * @return void
     */
    public function testNoConfiguredSecretYieldsNoneQuietly(?string $value): void
    {
        $logger = $this->logger();
        $logger->expects(self::never())->method('warning');

        self::assertNull($this->provider($value, $logger)->secret());
    }

    /**
     * @return array<string, array{0: string|null}>
     */
    public static function absentValues(): array
    {
        return [
            'not set' => [null],
            'empty' => [''],
        ];
    }

    /**
     * A value that cannot serve as a secret yields none, and the misconfiguration is logged.
     *
     * @dataProvider unusableValues
     * @param mixed $value Value at test_order/proof_secret
     * @return void
     */
    public function testAnUnusableValueYieldsNoneAndIsLogged(mixed $value): void
    {
        $logger = $this->logger();
        $logger->expects(self::once())
            ->method('warning')
            ->with(self::stringContains(DeploymentConfigSecretProvider::CONFIG_PATH));

        self::assertNull($this->provider($value, $logger)->secret());
    }

    /**
     * @return array<string, array{0: mixed}>
     */
    public static function unusableValues(): array
    {
        return [
            'one character short' => [str_repeat('k', 31)],
            'a number' => [12345678901234567890],
            'an array' => [['secret' => str_repeat('k', 40)]],
        ];
    }

    /**
     * A provider over a fixed deployment configuration value.
     *
     * @param mixed $value Value at test_order/proof_secret
     * @param LoggerInterface&MockObject $logger Logger to report to
     * @return DeploymentConfigSecretProvider
     */
    private function provider(mixed $value, LoggerInterface&MockObject $logger): DeploymentConfigSecretProvider
    {
        $deploymentConfig = $this->createMock(DeploymentConfig::class);
        $deploymentConfig->method('get')
            ->with(DeploymentConfigSecretProvider::CONFIG_PATH)
            ->willReturn($value);

        return new DeploymentConfigSecretProvider($deploymentConfig, $logger);
    }

    /**
     * @return LoggerInterface&MockObject
     */
    private function logger(): LoggerInterface&MockObject
    {
        return $this->createMock(LoggerInterface::class);
    }
}
