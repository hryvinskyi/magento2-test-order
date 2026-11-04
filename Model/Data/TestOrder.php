<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\TestOrder\Model\Data;

use Hryvinskyi\TestOrder\Api\Data\TestOrderInterface;

/**
 * A stored test-cart record, as read back. Immutable: the repository is the only way to change the record.
 *
 * Created through the generated `TestOrderInterfaceFactory`.
 */
class TestOrder implements TestOrderInterface
{
    /**
     * @param int $entityId Record id
     * @param int $quoteId Recognised cart
     * @param string $recognisedAt UTC, `Y-m-d H:i:s`
     * @param int|null $orderId Order placed from the cart, once linked
     * @param int $abandonAttempts Failed attempts of the clean-up job to end the cart's test orders
     * @throws \InvalidArgumentException When an id is not positive, the recognition time is empty or the attempts are
     *                                   negative
     */
    public function __construct(
        private readonly int $entityId,
        private readonly int $quoteId,
        private readonly string $recognisedAt,
        private readonly ?int $orderId = null,
        private readonly int $abandonAttempts = 0
    ) {
        if ($entityId <= 0 || $quoteId <= 0 || ($orderId !== null && $orderId <= 0)) {
            throw new \InvalidArgumentException('A test-order record names a stored record, cart and order by id.');
        }

        if ($recognisedAt === '') {
            throw new \InvalidArgumentException('A test-order record knows when its cart was recognised.');
        }

        if ($abandonAttempts < 0) {
            throw new \InvalidArgumentException('A test-order record counts its failed abandon attempts from zero.');
        }
    }

    /**
     * @inheritDoc
     */
    public function getEntityId(): int
    {
        return $this->entityId;
    }

    /**
     * @inheritDoc
     */
    public function getQuoteId(): int
    {
        return $this->quoteId;
    }

    /**
     * @inheritDoc
     */
    public function getOrderId(): ?int
    {
        return $this->orderId;
    }

    /**
     * @inheritDoc
     */
    public function getRecognisedAt(): string
    {
        return $this->recognisedAt;
    }

    /**
     * @inheritDoc
     */
    public function getAbandonAttempts(): int
    {
        return $this->abandonAttempts;
    }
}
