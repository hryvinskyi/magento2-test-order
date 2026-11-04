<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\TestOrder\Model;

use Hryvinskyi\TestOrder\Api\KeptTestOrderAnswersInterface;
use Hryvinskyi\TestOrder\Api\TestOrderRecordChangesInterface;
use Magento\Framework\ObjectManager\ResetAfterRequestInterface;

/**
 * The answers to "is this a test order?" already given in this request, shared by whoever asks and whoever writes.
 *
 * One instance per request (a shared service, reached through two ports): the record writer primes it, so a cart
 * recognised a moment ago is answered as a test cart even after an earlier "no" for it, and its order too.
 *
 * An application server forgets the answers between requests. A queue consumer has no requests: it keeps its answers
 * for the worker's life, so one that asks about a cart before the cart is placed keeps that "no" until it restarts.
 */
class TestOrderMemo implements
    KeptTestOrderAnswersInterface,
    TestOrderRecordChangesInterface,
    ResetAfterRequestInterface
{
    /**
     * @var array<int, bool> Answer per cart (quote) id
     */
    private array $byQuoteId = [];

    /**
     * @var array<int, bool> Answer per order id
     */
    private array $byOrderId = [];

    /**
     * @inheritDoc
     */
    public function forQuote(int $quoteId): ?bool
    {
        return $this->byQuoteId[$quoteId] ?? null;
    }

    /**
     * @inheritDoc
     */
    public function rememberQuote(int $quoteId, bool $isTestOrder): void
    {
        $this->byQuoteId[$quoteId] = $isTestOrder;
    }

    /**
     * @inheritDoc
     */
    public function forOrder(int $orderId): ?bool
    {
        return $this->byOrderId[$orderId] ?? null;
    }

    /**
     * @inheritDoc
     */
    public function rememberOrder(int $orderId, bool $isTestOrder): void
    {
        $this->byOrderId[$orderId] = $isTestOrder;
    }

    /**
     * @inheritDoc
     */
    public function recognised(int $quoteId): void
    {
        $this->byQuoteId[$quoteId] = true;
        $this->byOrderId = array_filter($this->byOrderId);
    }

    /**
     * @inheritDoc
     */
    public function forgotten(int $quoteId): void
    {
        $this->byQuoteId[$quoteId] = false;
    }

    /**
     * @inheritDoc
     */
    public function linked(int $quoteId, int $orderId): void
    {
        $this->byQuoteId[$quoteId] = true;
        $this->byOrderId[$orderId] = true;
    }

    /**
     * @inheritDoc
     */
    public function _resetState(): void
    {
        $this->byQuoteId = [];
        $this->byOrderId = [];
    }
}
