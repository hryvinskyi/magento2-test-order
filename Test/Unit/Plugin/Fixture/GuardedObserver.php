<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\TestOrder\Test\Unit\Plugin\Fixture;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;

/**
 * An observer that only counts how often it ran.
 */
class GuardedObserver implements ObserverInterface
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
