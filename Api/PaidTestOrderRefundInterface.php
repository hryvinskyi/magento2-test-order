<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\TestOrder\Api;

use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Api\Data\OrderInterface;

/**
 * Ends a test order that can no longer be cancelled because it was paid.
 *
 * A paid order is invoiced, and an invoiced order stays open for good unless a refund closes it.
 */
interface PaidTestOrderRefundInterface
{
    /**
     * Refund the order in full, if this shop may.
     *
     * The order handed in only names the order: another request may have ended it since it was read. What came of it is
     * in the answer's state, and a caller goes by that rather than by its own copy.
     *
     * @param OrderInterface $order The test order
     * @return OrderInterface The order as it stands: closed when it was refunded here or before, in whatever state it
     *                        is when it was refused and nothing changed. The order handed in, when the shop refused
     *                        without reading it or it no longer exists
     * @throws LocalizedException When the refund was started and failed
     */
    public function refund(OrderInterface $order): OrderInterface;
}
