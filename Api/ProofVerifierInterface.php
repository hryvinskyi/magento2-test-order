<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\TestOrder\Api;

use Hryvinskyi\TestOrder\Domain\TestOrderAction;
use Hryvinskyi\TestOrder\Domain\TestOrderProof;

/**
 * Decides whether a proof was signed by the end-to-end runner for one action on one subject, recently.
 */
interface ProofVerifierInterface
{
    /**
     * Whether the proof is genuine, fresh and bound to the action and the subject.
     *
     * Refused: a signature made with another secret or for another action, subject or time, a proof issued too long
     * before or after `$now`, an empty subject, and any proof at all while the shop has no usable secret.
     *
     * @param TestOrderProof $proof Proof as parsed from the request
     * @param TestOrderAction $action What the proof must allow
     * @param string $subject What the proof must be bound to: the cart's masked id, or a fixed word for a probe
     * @param int $now Current time, unix seconds
     * @return bool True only for a proof the shop accepts
     */
    public function verify(TestOrderProof $proof, TestOrderAction $action, string $subject, int $now): bool;
}
