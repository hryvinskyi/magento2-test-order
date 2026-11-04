<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\TestOrder\Domain;

/**
 * The end-to-end runner's proof that it placed a request: `<unix-seconds>.<signature>`.
 *
 * The signature is the lowercase hex HMAC-SHA256, made with the shop's test-order secret, of
 * `<action>|<subject>|<unix-seconds>` ({@see TestOrderAction}). Only that exact shape is accepted: ten digits without
 * a leading zero, a dot and 64 lowercase hex characters, nothing around them. Nothing in the value is decoded or
 * normalised, so what is compared later is what was sent.
 *
 * A proof is fresh for {@see self::FRESHNESS_SECONDS} either side of the time it names, so a proof cannot be replayed
 * long after it was made, nor made ahead for later use.
 *
 * Created through its generated factory: `create(['value' => $raw])`.
 */
class TestOrderProof
{
    /**
     * How far from now, in seconds and in either direction, a proof's time may be.
     */
    public const int FRESHNESS_SECONDS = 300;

    private const string SHAPE = '/\A([1-9]\d{9})\.([0-9a-f]{64})\z/';

    private readonly int $issuedAt;

    private readonly string $signature;

    /**
     * @param string $value The proof as sent
     * @throws MalformedTestOrderProofException When the value does not have the proof's shape
     */
    public function __construct(string $value)
    {
        if (preg_match(self::SHAPE, $value, $parts) !== 1) {
            throw new MalformedTestOrderProofException(
                'A test-order proof is ten digits of unix time, a dot and a 64-character lowercase hex signature.'
            );
        }

        $this->issuedAt = (int)$parts[1];
        $this->signature = $parts[2];
    }

    /**
     * The time the proof names.
     *
     * @return int Unix seconds
     */
    public function issuedAt(): int
    {
        return $this->issuedAt;
    }

    /**
     * The signature as sent.
     *
     * @return string 64 lowercase hex characters
     */
    public function signature(): string
    {
        return $this->signature;
    }

    /**
     * What the signature must have been made over for this action on this subject.
     *
     * @param TestOrderAction $action What the proof allows
     * @param string $subject Cart's masked id, or a fixed word for a probe
     * @return string `<action>|<subject>|<unix-seconds>`
     */
    public function signedMessage(TestOrderAction $action, string $subject): string
    {
        return $action->value . '|' . $subject . '|' . $this->issuedAt;
    }

    /**
     * Whether the proof's time is within the freshness window around now.
     *
     * @param int $now Current time, unix seconds
     * @return bool
     */
    public function isFreshAt(int $now): bool
    {
        return abs($now - $this->issuedAt) <= self::FRESHNESS_SECONDS;
    }
}
