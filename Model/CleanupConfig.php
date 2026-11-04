<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\TestOrder\Model;

use Hryvinskyi\TestOrder\Api\CleanupConfigInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;

/**
 * Reads the clean-up settings of test orders (Stores > Configuration > Hryvinskyi Extensions > Test Orders), set
 * globally.
 */
class CleanupConfig implements CleanupConfigInterface
{
    public const string XML_PATH_DELETE_CANCELLED = 'test_order/cleanup/delete_cancelled';

    public const string XML_PATH_ABANDON_AFTER_MINUTES = 'test_order/cleanup/abandon_after_minutes';

    /**
     * @param ScopeConfigInterface $scopeConfig Configuration values
     */
    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig
    ) {
    }

    /**
     * @inheritDoc
     */
    public function deletesCancelledTestOrders(): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_DELETE_CANCELLED);
    }

    /**
     * @inheritDoc
     *
     * A value that is not a number of at least one minute is replaced by the default, so a mistyped setting never
     * ends a test order the moment it is placed.
     */
    public function abandonAfterMinutes(): int
    {
        $minutes = $this->scopeConfig->getValue(self::XML_PATH_ABANDON_AFTER_MINUTES);
        if (!is_numeric($minutes) || (int)$minutes < 1) {
            return self::DEFAULT_ABANDON_AFTER_MINUTES;
        }

        return (int)$minutes;
    }
}
