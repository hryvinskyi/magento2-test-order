<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\TestOrder\Model;

use Hryvinskyi\TestOrder\Api\LivePaymentsDeclarationInterface;
use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\Exception\FileSystemException;
use Magento\Framework\Exception\RuntimeException;

/**
 * Reads the declaration from `app/etc/env.php` (`'test_order' => ['live_payments' => true]`).
 *
 * Only the boolean true declares it: a string such as "1" or "yes", left by a hand edit, does not, so a mistyped
 * value declares nothing. Whether test orders are then refused is for a guard to decide.
 */
class DeploymentConfigLivePaymentsDeclaration implements LivePaymentsDeclarationInterface
{
    public const string CONFIG_PATH = 'test_order/live_payments';

    /**
     * @param DeploymentConfig $deploymentConfig Values of app/etc/env.php
     */
    public function __construct(
        private readonly DeploymentConfig $deploymentConfig
    ) {
    }

    /**
     * @inheritDoc
     *
     * @throws FileSystemException When the deployment configuration cannot be read
     * @throws RuntimeException When the deployment configuration cannot be read
     */
    public function allowsTestOrdersOnLivePayments(): bool
    {
        return $this->deploymentConfig->get(self::CONFIG_PATH) === true;
    }
}
