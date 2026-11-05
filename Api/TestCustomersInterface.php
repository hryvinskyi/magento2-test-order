<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\TestOrder\Api;

/**
 * The customers a deployment names as test customers.
 *
 * A shop open only to signed-in customers has no guest carts, so a test order must be possible from a customer's cart,
 * and only from a customer named here: a proof on any other customer's cart stays refused.
 *
 * @api
 */
interface TestCustomersInterface
{
    /**
     * Whether the deployment names this e-mail address as a test customer's.
     *
     * The answer must rest on the customer account's address, never on one a visitor typed at checkout, which proves
     * nothing about who is placing the order.
     *
     * @param string $email The customer account's e-mail address; case and surrounding spaces do not matter, and an
     *                      empty one is never a test customer's
     * @return bool
     */
    public function isTestCustomer(string $email): bool;
}
