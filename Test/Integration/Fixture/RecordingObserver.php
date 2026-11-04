<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\TestOrder\Test\Integration\Fixture;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;

/**
 * An observer that acts on nothing and only counts how often it ran, guarded in place of a real integration's
 * observer so that no integration is ever called from a test.
 */
class RecordingObserver implements ObserverInterface
{
    /**
     * @var int
     */
    public int $runs = 0;

    /**
     * @inheritDoc
     */
    public function execute(Observer $observer): void
    {
        $this->runs++;
    }
}
