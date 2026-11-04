<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\TestOrder\Api;

/**
 * Where the end-to-end runner's proof of the request being served comes from.
 *
 * A request that can carry one (a REST or GraphQL call) reads it from the request; everywhere else (a scheduled
 * job, the command line) there is none. Code that verifies a proof asks this port and never touches the request.
 */
interface ProofSourceInterface
{
    /**
     * The raw proof sent with the request being served.
     *
     * @return string|null The value as sent, unparsed and untrimmed (an empty value is a value); null when the
     *                     request carries none
     */
    public function current(): ?string;
}
