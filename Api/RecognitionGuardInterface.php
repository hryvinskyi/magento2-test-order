<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\TestOrder\Api;

use Magento\Framework\Phrase;

/**
 * Vetoes recognising test orders in a store where placing one would be unsafe for an integration the guard knows.
 *
 * Guards form an open pool (the `guards` argument of {@see \Hryvinskyi\TestOrder\Model\RecognitionGuardPool}): the
 * module that knows an integration contributes its guard with a `di.xml` item, and this module never names it. The
 * pool is asked before a runner's proof is honoured — by the recognition probe for the store being served, and at
 * placement for the cart's store — and any one refusal refuses: the probe answers with the reason, and the placement
 * is refused with it before an order is created.
 *
 * @api
 */
interface RecognitionGuardInterface
{
    /**
     * Why test orders must not be recognised in the store, if they must not.
     *
     * @param int $storeId Store being served, or the store of the cart being placed
     * @return Phrase|null Null when the guard has no objection; otherwise the reason, a full sentence for the person
     *                     running the tests
     */
    public function refusalFor(int $storeId): ?Phrase;
}
