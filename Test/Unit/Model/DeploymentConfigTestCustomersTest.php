<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\TestOrder\Test\Unit\Model;

use Hryvinskyi\TestOrder\Model\DeploymentConfigTestCustomers;
use Magento\Framework\App\DeploymentConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * A customer is a test customer only when app/etc/env.php lists the address, whatever its case and surrounding spaces;
 * anything but a list of non-empty strings names nobody, and says so in the log.
 */
class DeploymentConfigTestCustomersTest extends TestCase
{
    private const string NAMED = 'buyer@example.test';

    /**
     * A usable list names the customers on it and nobody else, with no warning.
     *
     * @dataProvider usableLists
     * @param mixed $configured Value at test_order/customers
     * @param string $email Address asked about
     * @param bool $isTestCustomer Whether it is a test customer's
     * @return void
     */
    public function testAUsableListNamesExactlyItsCustomers(mixed $configured, string $email, bool $isTestCustomer): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('warning');

        self::assertSame($isTestCustomer, $this->testCustomers($configured, $logger)->isTestCustomer($email));
    }

    /**
     * The usable lists, an address and whether it is named.
     *
     * @return array<string, array{mixed, string, bool}>
     */
    public static function usableLists(): array
    {
        return [
            'a named customer' => [[self::NAMED], self::NAMED, true],
            'the second one named' => [['other@example.test', self::NAMED], self::NAMED, true],
            'a different case' => [[self::NAMED], 'Buyer@EXAMPLE.test', true],
            'a different case in the list' => [['BUYER@Example.Test'], self::NAMED, true],
            'a different case beyond ascii' => [['bÜyer@example.test'], 'bÜYER@example.test', true],
            'surrounding spaces' => [[self::NAMED], "  buyer@example.test\n", true],
            'surrounding spaces in the list' => [['  buyer@example.test '], self::NAMED, true],
            'an unnamed customer' => [[self::NAMED], 'someone@example.test', false],
            'a part of a named address' => [[self::NAMED], 'buyer@example', false],
            'no key' => [null, self::NAMED, false],
            'an empty list' => [[], self::NAMED, false],
            'an empty e-mail' => [[self::NAMED], '', false],
            'an e-mail of spaces' => [[self::NAMED], '   ', false],
            'an empty e-mail and no key' => [null, '', false],
        ];
    }

    /**
     * A value that is not a list of non-empty strings names nobody and is warned about without repeating its content.
     *
     * @dataProvider unusableLists
     * @param mixed $configured Value at test_order/customers
     * @return void
     */
    public function testAnythingButAListOfNonEmptyStringsNamesNobody(mixed $configured): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning')->with(
            self::logicalAnd(
                self::stringContains('test_order/customers'),
                self::logicalNot(self::stringContains(self::NAMED))
            )
        );

        self::assertFalse($this->testCustomers($configured, $logger)->isTestCustomer(self::NAMED));
    }

    /**
     * The values that are no usable list.
     *
     * @return array<string, array{mixed}>
     */
    public static function unusableLists(): array
    {
        return [
            'a string instead of a list' => [self::NAMED],
            'the boolean true' => [true],
            'a number' => [1],
            'a list holding a number' => [[self::NAMED, 12]],
            'a list holding null' => [[self::NAMED, null]],
            'a list holding a list' => [[[self::NAMED]]],
            'a list holding an empty string' => [[self::NAMED, '']],
            'a list holding only spaces' => [[self::NAMED, '   ']],
            'keys instead of a list' => [['buyer' => self::NAMED]],
            'a list with a gap' => [[1 => self::NAMED]],
        ];
    }

    /**
     * An empty e-mail is never a test customer's, so nothing is read for it and an unusable list is not reported.
     *
     * @return void
     */
    public function testAnEmptyEmailReadsNothing(): void
    {
        $deploymentConfig = $this->createMock(DeploymentConfig::class);
        $deploymentConfig->expects(self::never())->method('get');

        self::assertFalse(
            (new DeploymentConfigTestCustomers($deploymentConfig, $this->createStub(LoggerInterface::class)))
                ->isTestCustomer('')
        );
    }

    /**
     * The test customers of a deployment configured with the value.
     *
     * @param mixed $configured Value at test_order/customers
     * @param LoggerInterface $logger Where an unusable list is reported
     * @return DeploymentConfigTestCustomers
     */
    private function testCustomers(mixed $configured, LoggerInterface $logger): DeploymentConfigTestCustomers
    {
        $deploymentConfig = $this->createStub(DeploymentConfig::class);
        $deploymentConfig->method('get')->willReturnCallback(
            static fn (string $path): mixed => $path === 'test_order/customers' ? $configured : null
        );

        return new DeploymentConfigTestCustomers($deploymentConfig, $logger);
    }
}
