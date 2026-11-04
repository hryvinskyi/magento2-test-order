<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\TestOrder\Model;

use Hryvinskyi\TestOrder\Api\OrderDeletionInterface;
use Magento\Framework\Registry;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\OrderRepositoryInterface;

/**
 * Deletes an order with the registry's secure-area flag up.
 *
 * Magento protects orders from deletion outside the admin and the Web API, so a delete in the cron area throws unless
 * that flag is set. The flag is up for this one delete and no longer: whatever the registry held before is what it
 * holds afterwards, a value when there was one and nothing when there was none, also when the delete throws.
 */
class SecureAreaOrderDeletion implements OrderDeletionInterface
{
    private const string SECURE_AREA = 'isSecureArea';

    /**
     * @param OrderRepositoryInterface $orderRepository Deletes the order
     * @param Registry $registry Holds the secure-area flag
     */
    public function __construct(
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly Registry $registry
    ) {
    }

    /**
     * @inheritDoc
     */
    public function delete(OrderInterface $order): void
    {
        $earlier = $this->registry->registry(self::SECURE_AREA);
        $this->registry->unregister(self::SECURE_AREA);
        $this->registry->register(self::SECURE_AREA, true);

        try {
            $this->orderRepository->delete($order);
        } finally {
            $this->registry->unregister(self::SECURE_AREA);
            if ($earlier !== null) {
                $this->registry->register(self::SECURE_AREA, $earlier);
            }
        }
    }
}
