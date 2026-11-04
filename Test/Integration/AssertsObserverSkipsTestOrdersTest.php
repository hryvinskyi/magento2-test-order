<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\TestOrder\Test\Integration;

use Hryvinskyi\TestOrder\Plugin\SkipObserverForTestOrders;
use Hryvinskyi\TestOrder\Test\Integration\Fixture\OptedOutObserver;
use Hryvinskyi\TestOrder\Test\Integration\Fixture\RecordingObserver;
use Magento\Framework\Interception\PluginList\PluginList;
use Magento\Framework\Interception\PluginListInterface;
use Magento\TestFramework\Helper\Bootstrap;
use PHPUnit\Framework\ExpectationFailedException;
use PHPUnit\Framework\TestCase;

/**
 * The trait another module proves its opt-out with: it passes for an observer the real plugin list wraps in the plugin
 * that skips test orders, and fails for one nothing wraps, which is the mistake it exists to catch.
 *
 * @magentoAppIsolation enabled
 * @magentoAppArea global
 */
class AssertsObserverSkipsTestOrdersTest extends TestCase
{
    use AssertsObserverSkipsTestOrders;

    /**
     * An observer wrapped under the agreed name, by the plugin that skips test orders, passes.
     *
     * @return void
     */
    public function testAnObserverWrappedByThePluginPasses(): void
    {
        $this->wrapInThePlugin(OptedOutObserver::class);

        self::assertObserverSkipsTestOrders(OptedOutObserver::class);
    }

    /**
     * An observer no module opted out fails, whatever other observers are wrapped.
     *
     * @return void
     */
    public function testAnObserverNothingWrapsFails(): void
    {
        $this->wrapInThePlugin(OptedOutObserver::class);

        $this->expectException(ExpectationFailedException::class);

        self::assertObserverSkipsTestOrders(RecordingObserver::class);
    }

    /**
     * Add the plugin to the area's plugin list, as a module's `di.xml` would.
     *
     * The list loads the area's configuration on first use and would then replace what was merged, so it is read
     * first, about a class other than the one being wrapped.
     *
     * @param class-string $observerClass Observer to wrap
     * @return void
     */
    private function wrapInThePlugin(string $observerClass): void
    {
        $pluginList = Bootstrap::getObjectManager()->get(PluginListInterface::class);
        self::assertInstanceOf(PluginList::class, $pluginList);

        $pluginList->getNext(SkipObserverForTestOrders::class, 'aroundExecute');
        $pluginList->merge([
            $observerClass => [
                'plugins' => [
                    'skip_test_orders' => ['instance' => SkipObserverForTestOrders::class, 'sortOrder' => 0],
                ],
            ],
        ]);
    }
}
