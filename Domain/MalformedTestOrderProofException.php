<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\TestOrder\Domain;

/**
 * A value sent as a test-order proof that does not have the proof's shape.
 */
class MalformedTestOrderProofException extends \InvalidArgumentException
{
}
