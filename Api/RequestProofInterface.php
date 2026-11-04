<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\TestOrder\Api;

use Hryvinskyi\TestOrder\Domain\TestOrderAction;
use Magento\Framework\Exception\AuthorizationException;

/**
 * The end-to-end runner's proof sent with the request being served, judged now for one action on one subject.
 *
 * The one place a sent proof is parsed and checked against the shop's secret and the current time, for every surface
 * that honours a proof.
 */
interface RequestProofInterface
{
    /**
     * Whether the request carries a proof at all, valid or not.
     *
     * @return bool
     */
    public function isSent(): bool;

    /**
     * Whether the request carries a well-formed, fresh proof made with the shop's secret for the action on the subject.
     *
     * @param TestOrderAction $action What the proof must allow
     * @param string $subject What the proof must be made over
     * @return bool False as well when no proof was sent or the shop has no usable secret
     */
    public function isValidFor(TestOrderAction $action, string $subject): bool;

    /**
     * Refuse the request unless it carries a valid proof for the action on the subject.
     *
     * @param TestOrderAction $action What the proof must allow
     * @param string $subject What the proof must be made over
     * @return void
     * @throws AuthorizationException When the proof is absent, malformed, stale, made for another action, subject or
     *                                with another secret, or the shop has no usable secret
     */
    public function authorise(TestOrderAction $action, string $subject): void;
}
