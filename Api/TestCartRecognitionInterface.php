<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\TestOrder\Api;

use Magento\Framework\Exception\LocalizedException;

/**
 * Decides whether a cart placed with a proof may become a test cart, and records it when it may.
 */
interface TestCartRecognitionInterface
{
    /**
     * Record the cart as a test cart, or refuse.
     *
     * @param int $quoteId Cart (quote) entity id
     * @param string|null $paymentMethod The method the placement names; null when it names none and the cart's own
     *                                   counts
     * @return void
     * @throws LocalizedException When the proof sent with the request cannot be honoured; a cart that does not exist is
     *                            refused like any other, as no proof can be valid for it
     */
    public function recognise(int $quoteId, ?string $paymentMethod = null): void;
}
