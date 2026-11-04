<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\TestOrder\Api;

/**
 * The end-to-end runner asks whether the shop recognises its proofs before it places anything:
 * `POST /V1/test-orders/recognition` (a POST, so that no cache in front of the shop can answer it).
 *
 * The proof is sent in the `X-Test-Order-Proof` header, made for the action `recognise` over {@see self::SUBJECT}. A
 * runner the shop does not recognise must not place an order: it would be an ordinary one, or be refused.
 *
 * @api
 */
interface TestOrderRecognitionInterface
{
    /**
     * What a recognition proof is made over.
     */
    public const string SUBJECT = 'recognition';

    /**
     * Confirm that the proof sent with this request is recognised, in the store being served.
     *
     * @return bool Always true; any other outcome is an error
     * @throws \Magento\Framework\Exception\AuthorizationException When no valid proof was sent, or the shop has no
     *                                                             secret (HTTP 401)
     * @throws \Magento\Framework\Webapi\Exception When a recognition guard refuses test orders in the store; the
     *                                              message is the guard's reason (HTTP 403)
     */
    public function confirm(): bool;
}
