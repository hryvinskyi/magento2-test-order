<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\TestOrder\Test\Unit\Fixture;

use Hryvinskyi\TestOrder\Api\UndoClaimsInterface;

/**
 * Undo claims kept in memory with the real rule: the first claim for an order wins, every later one loses.
 */
class InMemoryUndoClaims implements UndoClaimsInterface
{
    /**
     * @var list<int> Orders claimed, in the order they were claimed
     */
    public array $claimed = [];

    /**
     * @inheritDoc
     */
    public function claim(int $orderId): bool
    {
        if ($orderId <= 0 || in_array($orderId, $this->claimed, true)) {
            return false;
        }
        $this->claimed[] = $orderId;

        return true;
    }
}
