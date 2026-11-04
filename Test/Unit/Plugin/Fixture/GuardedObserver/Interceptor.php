<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\TestOrder\Test\Unit\Plugin\Fixture\GuardedObserver;

use Hryvinskyi\TestOrder\Test\Unit\Plugin\Fixture\GuardedObserver;

/**
 * Stands in for the interceptor the container generates around an observer that carries a plugin.
 */
class Interceptor extends GuardedObserver
{
}
