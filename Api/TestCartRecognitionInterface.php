<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\TestOrder\Api;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;

/**
 * Decides whether a cart placed with a proof may become a test cart, and records it when it may.
 */
interface TestCartRecognitionInterface
{
    /**
     * Record the cart as a test cart, or refuse.
     *
     * @param int $quoteId Cart (quote) entity id
     * @return void
     * @throws LocalizedException When the proof sent with the request cannot be honoured
     * @throws NoSuchEntityException When the cart does not exist
     */
    public function recognise(int $quoteId): void;
}
