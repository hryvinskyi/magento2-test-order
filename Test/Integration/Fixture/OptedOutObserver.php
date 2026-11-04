<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\TestOrder\Test\Integration\Fixture;

/**
 * An observer a test wraps in the plugin that skips test orders, a class of its own so that the plain
 * {@see RecordingObserver} stays unwrapped.
 */
class OptedOutObserver extends RecordingObserver
{
}
