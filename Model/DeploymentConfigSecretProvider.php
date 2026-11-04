<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\TestOrder\Model;

use Hryvinskyi\TestOrder\Api\SecretProviderInterface;
use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\Exception\FileSystemException;
use Magento\Framework\Exception\RuntimeException;
use Psr\Log\LoggerInterface;

/**
 * Reads the test-order secret from `app/etc/env.php` (`'test_order' => ['proof_secret' => '…']`).
 *
 * Only the deployment configuration holds it: never the database, where an admin screen, a backup or a copied
 * staging database would expose it. A value that is set but unusable is logged, because a shop that silently
 * recognises nothing looks exactly like a runner with the wrong secret.
 */
class DeploymentConfigSecretProvider implements SecretProviderInterface
{
    public const string CONFIG_PATH = 'test_order/proof_secret';

    /**
     * @param DeploymentConfig $deploymentConfig Values of app/etc/env.php
     * @param LoggerInterface $logger Where an unusable secret is reported
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
    public function secret(): ?string
    {
        $value = $this->deploymentConfig->get(self::CONFIG_PATH);
        if ($value === null || $value === '') {
            return null;
        }

        if (!is_string($value) || strlen($value) < self::MINIMUM_LENGTH) {
            $this->logger->warning(sprintf(
                'The test-order secret at %s in app/etc/env.php is not a string of at least %d characters; '
                . 'no test order is recognised.',
                self::CONFIG_PATH,
                self::MINIMUM_LENGTH
            ));

            return null;
        }

        return $value;
    }
}
