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
     * Cancel every test order of the cart, refund each that was paid, take back what placing each did, and delete each
     * cancelled one when the shop is configured to.
     *
     * - An order that can be cancelled is cancelled; cancelling runs the undo steps.
     * - An order that can no longer be cancelled is refunded, which closes it, and then undone directly. A refund that
     *   fails on an order no part of which was cancelled leaves it not undone, so the clean-up job comes back for it.
     *   On an order partly cancelled the cancellation has already undone it: the failure reaches the caller and nothing
     *   comes back to it.
     * - An order that can be neither is undone, left as it is and logged for a person to end.
     * - An order already closed has ended: it is only undone, if it never was.
     * - Only a cancelled order is deleted, and only while deleting cancelled test orders is switched on.
     * - Every order is attempted even when an earlier one fails; calling this again is safe.
     *
     * @param int $quoteId Cart (quote) entity id
     * @return AbandonResultInterface What became of an order left open when there is one, otherwise of the newest
     *                                order: `canceled` once cancelled, `closed` once refunded, and any other state
     *                                means it could be neither; deleted only when every order was deleted
     * @throws NoSuchEntityException When the cart was never recognised, or no order was placed from it
     * @throws LocalizedException When cancelling, refunding or deleting an order fails, after every order was
     *                            attempted
     * @throws \Throwable Whatever else a cancellation, a refund or a delete threw, after every order was attempted
     */
    public function abandon(int $quoteId): AbandonResultInterface;
}
