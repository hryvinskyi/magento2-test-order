<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\TestOrder\Model;

use Hryvinskyi\TestOrder\Api\TestCustomersInterface;
use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\Exception\FileSystemException;
use Magento\Framework\Exception\RuntimeException;
use Psr\Log\LoggerInterface;

/**
 * Reads the test customers from `app/etc/env.php` (`'test_order' => ['customers' => ['buyer@example.test']]`).
 *
 * Only the deployment configuration holds them: never the database, so a copy of a shop's data carries no such right.
 * Anything but a list of non-empty strings is no list at all, so one mistyped entry names nobody rather than some of
 * the customers. Such a value is logged, because a shop that silently refuses a named customer looks exactly like a
 * runner signing in with the wrong account.
 */
class DeploymentConfigTestCustomers implements TestCustomersInterface
{
    public const string CONFIG_PATH = 'test_order/customers';

    /**
     * @param DeploymentConfig $deploymentConfig Values of app/etc/env.php
     * @param LoggerInterface $logger Where an unusable list is reported
     */
    public function __construct(
        private readonly DeploymentConfig $deploymentConfig,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @inheritDoc
     *
     * @throws FileSystemException When the deployment configuration cannot be read
     * @throws RuntimeException When the deployment configuration cannot be read
     */
    public function isTestCustomer(string $email): bool
    {
        $email = $this->comparable($email);

        return $email !== '' && in_array($email, $this->namedCustomers(), true);
    }

    /**
     * The addresses the deployment names, in the form they are compared in.
     *
     * @return list<string>
     * @throws FileSystemException When the deployment configuration cannot be read
     * @throws RuntimeException When the deployment configuration cannot be read
     */
    private function namedCustomers(): array
    {
        $value = $this->deploymentConfig->get(self::CONFIG_PATH);
        if ($value === null) {
            return [];
        }

        if (!is_array($value) || !array_is_list($value)) {
            return $this->unusable();
        }

        $customers = [];
        foreach ($value as $customer) {
            $customer = is_string($customer) ? $this->comparable($customer) : '';
            if ($customer === '') {
                return $this->unusable();
            }

            $customers[] = $customer;
        }

        return $customers;
    }

    /**
     * An address in the form addresses are compared in.
     *
     * @param string $email E-mail address as written or stored
     * @return string
     */
    private function comparable(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    /**
     * Report the value at the configuration path as unusable. The value itself stays out of the log: it holds
     * customers' addresses.
     *
     * @return list<string> Nobody
     */
    private function unusable(): array
    {
        $this->logger->warning(sprintf(
            'The test customers at %s in app/etc/env.php are not a list of non-empty strings; nobody is named.',
            self::CONFIG_PATH
        ));

        return [];
    }
}
