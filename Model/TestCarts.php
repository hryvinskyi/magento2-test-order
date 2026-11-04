<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\TestOrder\Model;

use Hryvinskyi\TestOrder\Api\KeptTestOrderAnswersInterface;
use Hryvinskyi\TestOrder\Api\TestCartsInterface;
use Magento\Framework\App\ResourceConnection;

/**
 * Answers whether a cart is a test cart from the test-cart records, once per cart and request.
 *
 * A lookup either finds a record's id or finds nothing (the driver's `false`), so "is a test cart" is "an id came
 * back".
 */
class TestCarts implements TestCartsInterface
{
    /**
     * @param ResourceConnection $resourceConnection Database connection provider
     * @param KeptTestOrderAnswersInterface $answers The request's kept answers
     */
    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly KeptTestOrderAnswersInterface $answers
    ) {
    }

    /**
     * @inheritDoc
     */
    public function isTestCart(int $quoteId): bool
    {
        if ($quoteId <= 0) {
            return false;
        }

        $known = $this->answers->forQuote($quoteId);
        if ($known !== null) {
            return $known;
        }

        $connection = $this->resourceConnection->getConnection();
        $isTestCart = is_numeric($connection->fetchOne(
            $connection->select()
                ->from($this->resourceConnection->getTableName(TestOrderRepository::TABLE_NAME), ['entity_id'])
                ->where('quote_id = ?', $quoteId)
                ->limit(1)
        ));
        $this->answers->rememberQuote($quoteId, $isTestCart);

        return $isTestCart;
    }
}
