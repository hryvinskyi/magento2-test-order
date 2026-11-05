<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\TestOrder\Test\Integration;

use Hryvinskyi\TestOrder\Plugin\SkipObserverForTestOrders;
use Magento\Framework\Interception\DefinitionInterface;
use Magento\Framework\Interception\PluginList\PluginList;
use Magento\Framework\Interception\PluginListInterface;
use Magento\TestFramework\Helper\Bootstrap;

/**
 * For the integration test of a module that keeps its own observers away from test orders: proves, through the
 * plugin list the container builds for the area the test runs in, that an observer is wrapped by the plugin that
 * skips test orders — without running the observer.
 *
 * The plugin is expected under the name every opting-out module gives it, `skip_test_orders`, as an `around` plugin
 * on `execute`. The plugin list keeps an entry for a class that no longer exists, so a class is checked first: a
 * vendor's rename would otherwise leave the assertion green and the new class not opted out.
 */
trait AssertsObserverSkipsTestOrders
{
    /**
     * Assert the observer's `execute` passes through the plugin that skips test orders in the current area.
     *
     * @param string $observerClass Observer the module declares in its events configuration
     * @return void
     */
    private static function assertObserverSkipsTestOrders(string $observerClass): void
    {
        if (!class_exists($observerClass)) {
            self::fail(sprintf(
                '%s does not exist, so nothing is opted out. Opt out the class that replaced it.',
                $observerClass
            ));
        }

        $pluginList = Bootstrap::getObjectManager()->get(PluginListInterface::class);
        self::assertInstanceOf(PluginList::class, $pluginList);

        self::assertContains(
            'skip_test_orders',
            self::aroundPluginsOnExecute($pluginList, $observerClass),
            sprintf('%s::execute() is not wrapped by the plugin that skips test orders.', $observerClass)
        );
        self::assertInstanceOf(
            SkipObserverForTestOrders::class,
            $pluginList->getPlugin($observerClass, 'skip_test_orders'),
            sprintf('The plugin named skip_test_orders on %s is not the one that skips test orders.', $observerClass)
        );
    }

    /**
     * The `around` plugins on a class's `execute`, in the order they run.
     *
     * @param PluginList $pluginList Plugin list of the current area
     * @param class-string $type Intercepted class
     * @return list<string>
     */
    private static function aroundPluginsOnExecute(PluginList $pluginList, string $type): array
    {
        $codes = [];
        $code = '__self';
        while (true) {
            // A class without plugins on the method has no entry at all, whatever the declared return type says.
            $around = $pluginList->getNext($type, 'execute', $code)[DefinitionInterface::LISTENER_AROUND] ?? null;
            if (!is_string($around) || in_array($around, $codes, true)) {
                return $codes;
            }
            $codes[] = $around;
            $code = $around;
        }
    }
}
