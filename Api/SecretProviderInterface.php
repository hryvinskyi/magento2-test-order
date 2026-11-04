<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\TestOrder\Api;

/**
 * The shop's test-order secret, shared only with the end-to-end runner.
 *
 * A secret shorter than {@see self::MINIMUM_LENGTH} characters is not a secret: the shop then recognises nothing.
 */
interface SecretProviderInterface
{
    /**
     * Fewest characters a usable secret has.
     */
    public const int MINIMUM_LENGTH = 32;

    /**
     * The configured secret.
     *
     * @return string|null The secret; null when none of at least {@see self::MINIMUM_LENGTH} characters is configured
     */
    public function secret(): ?string;
}
