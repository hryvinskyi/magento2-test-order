<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\TestOrder\Model\Data;

use Hryvinskyi\TestOrder\Api\Data\AbandonResultInterface;

/**
 * What abandoning a cart's test orders did: about an order left open when there is one, otherwise the newest.
 * Immutable, and valid by construction: an order always has a state.
 *
 * Created through the generated `AbandonResultInterfaceFactory`.
 */
class AbandonResult implements AbandonResultInterface
{
    /**
     * @param string $state The order's state afterwards
     * @param bool $deleted Whether every order of the cart was deleted
     * @param string|null $incrementId The order's number; null when it has none
     * @throws \InvalidArgumentException When the state or a given number is empty
     */
    public function __construct(
        private readonly string $state,
        private readonly bool $deleted,
        private readonly ?string $incrementId = null
    ) {
        if ($state === '') {
            throw new \InvalidArgumentException('An abandoned test order is in some state.');
        }

        if ($incrementId === '') {
            throw new \InvalidArgumentException('An abandoned test order has a number or none at all.');
        }
    }

    /**
     * @inheritDoc
     */
    public function getIncrementId(): ?string
    {
        return $this->incrementId;
    }

    /**
     * @inheritDoc
     */
    public function getState(): string
    {
        return $this->state;
    }

    /**
     * @inheritDoc
     */
    public function isDeleted(): bool
    {
        return $this->deleted;
    }
}
