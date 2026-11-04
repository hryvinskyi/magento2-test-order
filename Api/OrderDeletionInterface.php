<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\TestOrder\Api;

use Magento\Sales\Api\Data\OrderInterface;

/**
 * Deletes an order wherever the code runs, the clean-up cron included, where Magento on its own refuses to delete an
 * order.
 */
interface OrderDeletionInterface
{
    /**
     * Delete the order.
     *
     * @param OrderInterface $order The saved order
     * @return void
     * @throws \Magento\Framework\Exception\CouldNotDeleteException When the order cannot be deleted
     */
    public function delete(OrderInterface $order): void;
}
