<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\TestOrder\Api;

use Hryvinskyi\TestOrder\Api\Data\AbandonResultInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;

/**
 * Ends every test order placed from a recognised cart, for the shop's own processes.
 *
 * No proof is asked for here: whoever calls this has already established the right to (the REST surface by the
 * runner's proof, the clean-up job by being the shop). It acts only on orders placed from a recognised cart, so it
 * can never end an ordinary order.
 */
interface AbandonTestOrderInterface
{
    /**
     * Cancel every test order of the cart, take back what placing each did, and delete each cancelled one when the
     * shop is configured to.
     *
     * - An order that can be cancelled is cancelled; cancelling runs the undo steps.
     * - An order that can no longer be cancelled is undone directly, left as it is, and logged for a person to end.
     * - Only a cancelled order is deleted, and only while deleting cancelled test orders is switched on.
     * - Every order is attempted even when an earlier one fails; calling this again is safe.
     *
     * @param int $quoteId Cart (quote) entity id
     * @return AbandonResultInterface What became of an order left open when there is one, otherwise of the newest
     *                                order: `canceled` once cancelled, and any other state means it could no longer
     *                                be cancelled; deleted only when every order was deleted
     * @throws NoSuchEntityException When the cart was never recognised, or no order was placed from it
     * @throws LocalizedException When cancelling or deleting an order fails, after every order was attempted
     * @throws \Throwable Whatever else a cancellation or a delete threw, after every order was attempted
     */
    public function abandon(int $quoteId): AbandonResultInterface;
}
