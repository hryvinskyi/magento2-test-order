<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\TestOrder\Model\ProofSource;

use Hryvinskyi\TestOrder\Api\ProofSourceInterface;

/**
 * The proof source wherever no request can carry a proof: a scheduled job, the command line, a storefront page.
 */
class NullProofSource implements ProofSourceInterface
{
    /**
     * @inheritDoc
     */
    public function current(): ?string
    {
        return null;
    }
}
