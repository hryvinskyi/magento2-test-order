<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\TestOrder\Api;

/**
 * Whether this installation is declared as a shop that may place test orders while it takes payments on live keys.
 *
 * The declaration lives only in `app/etc/env.php` (`'test_order' => ['live_payments' => true]`) and is made on
 * production alone. It is never in the database, so a copy of the production database — on a developer's machine or a
 * staging server — does not carry it: there, a guard that sees live keys refuses test orders, because ending a local
 * test order would act on the live payment that carries the same order number.
 *
 * @api
 */
interface LivePaymentsDeclarationInterface
{
    /**
     * Whether test orders may be placed although payments run on live keys.
     *
     * @return bool True only when the declaration is the boolean true
     */
    public function allowsTestOrdersOnLivePayments(): bool;
}
