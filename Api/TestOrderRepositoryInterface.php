<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\TestOrder\Api;

use Hryvinskyi\TestOrder\Api\Data\TestOrderInterface;
use Magento\Framework\Exception\NoSuchEntityException;

/**
 * Reads the record of a cart the shop recognised as a test cart.
 *
 * Kept apart from {@see TestOrdersInterface} so that the integrations which only ask whether an order is a test
 * order depend on no record at all; what writes records is {@see TestCartRecordsInterface}, and what the clean-up job
 * asks is {@see StaleTestCartsInterface}.
 */
interface TestOrderRepositoryInterface
{
    /**
     * The record of a recognised cart.
     *
     * @param int $quoteId Cart (quote) entity id
     * @return TestOrderInterface
     * @throws NoSuchEntityException When the cart was never recognised
     */
    public function getByQuoteId(int $quoteId): TestOrderInterface;
}
