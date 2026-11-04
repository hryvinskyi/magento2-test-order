<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\TestOrder\Cron;

use Hryvinskyi\TestOrder\Api\AbandonTestOrderInterface;
use Hryvinskyi\TestOrder\Api\CartOrdersInterface;
use Hryvinskyi\TestOrder\Api\CleanupConfigInterface;
use Hryvinskyi\TestOrder\Api\StaleTestCartsInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Psr\Log\LoggerInterface;

/**
 * The safety net behind the runner: ends every test order the runner left behind, and forgets carts from which no
 * order was ever placed.
 *
 * - A cart with an order not yet undone a configured number of minutes after the cart was recognised has all its
 *   orders abandoned, exactly as the runner would have done.
 * - While cancelled test orders are deleted, so has a cart with a cancelled order that still exists: its delete failed,
 *   or someone else cancelled the order. Being undone does not take it out of the job's hands.
 * - A cart whose orders could not be ended is tried again on the next run, up to {@see self::MAX_FAILED_ATTEMPTS}
 *   times in all. Then it is no longer tried: the failure is reported as critical with the cart and its order
 *   numbers, and the record stays, so its orders remain test orders until a person ends them.
 * - A record whose cart has no order is removed a day after the cart was last recognised; a record whose cart has an
 *   order stays as long as the order does.
 */
class AbandonStaleTestOrders
{
    /**
     * Failed attempts after which a cart's test orders are left for a person.
     */
    public const int MAX_FAILED_ATTEMPTS = 5;

    /**
     * Most carts ended in one run, so a backlog is worked off over several runs.
     */
    private const int BATCH_SIZE = 50;

    /**
     * How long a record whose cart has no order is kept: a day.
     */
    private const int ORDERLESS_RECORD_MINUTES = 1440;

    /**
     * @param StaleTestCartsInterface $staleCarts The carts left behind, and the records of carts without an order
     * @param AbandonTestOrderInterface $abandonTestOrder Ends a cart's test orders
     * @param CartOrdersInterface $cartOrders Names the orders of a cart that could not be ended
     * @param CleanupConfigInterface $config When a test order is ended, and whether a cancelled one is deleted
     * @param LoggerInterface $logger Where a cart that could not be ended is reported
     */
    public function __construct(
        private readonly StaleTestCartsInterface $staleCarts,
        private readonly AbandonTestOrderInterface $abandonTestOrder,
        private readonly CartOrdersInterface $cartOrders,
        private readonly CleanupConfigInterface $config,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * End the test orders left behind, then forget the carts that never became an order.
     *
     * @return void
     */
    public function execute(): void
    {
        $stale = $this->staleCarts->getStaleQuoteIds(
            $this->config->abandonAfterMinutes(),
            self::MAX_FAILED_ATTEMPTS,
            self::BATCH_SIZE,
            $this->config->deletesCancelledTestOrders()
        );
        foreach ($stale as $quoteId) {
            $this->abandon($quoteId);
        }

        $this->staleCarts->removeOrderless(self::ORDERLESS_RECORD_MINUTES);
    }

    /**
     * Abandon one cart's test orders, counting and reporting a failure instead of stopping the run.
     *
     * Errors count too: a type error in a third party's observer of the cancellation must not cost the later carts
     * their turn, or put this cart first again on every run without a count.
     *
     * @param int $quoteId Cart (quote) entity id
     * @return void
     */
    private function abandon(int $quoteId): void
    {
        try {
            $this->abandonTestOrder->abandon($quoteId);
        } catch (\Throwable $failure) {
            $this->reportFailure($quoteId, $this->staleCarts->recordFailedAbandon($quoteId), $failure);
        }
    }

    /**
     * Report a failed attempt: an error while it is tried again, a critical failure once it is given up.
     *
     * @param int $quoteId Cart (quote) entity id
     * @param int $attempts Failed attempts so far, this one included
     * @param \Throwable $failure Why the attempt failed
     * @return void
     */
    private function reportFailure(int $quoteId, int $attempts, \Throwable $failure): void
    {
        $context = ['exception' => $failure, 'quote_id' => $quoteId, 'attempts' => $attempts];
        if ($attempts < self::MAX_FAILED_ATTEMPTS) {
            $this->logger->error(
                sprintf(
                    'The test orders of cart %d could not be abandoned (attempt %d of %d) and are tried again on '
                    . 'the next run: %s',
                    $quoteId,
                    $attempts,
                    self::MAX_FAILED_ATTEMPTS,
                    $failure->getMessage()
                ),
                $context
            );

            return;
        }

        $this->logger->critical(
            sprintf(
                'The test orders of cart %d (orders %s) could not be abandoned after %d attempts and are no longer '
                . 'tried; they stay test orders and must be cancelled by a person: %s',
                $quoteId,
                $this->orderNumbersOf($quoteId),
                $attempts,
                $failure->getMessage()
            ),
            $context
        );
    }

    /**
     * The numbers of the cart's orders, for a person to find them.
     *
     * @param int $quoteId Cart (quote) entity id
     * @return string Comma-separated order numbers; a note when they cannot be read
     */
    private function orderNumbersOf(int $quoteId): string
    {
        try {
            $numbers = array_map(
                static fn (OrderInterface $order): string => (string)$order->getIncrementId(),
                $this->cartOrders->ofCart($quoteId)
            );
        } catch (\Throwable) {
            return 'unreadable';
        }

        return $numbers === [] ? 'none found' : implode(', ', $numbers);
    }
}
