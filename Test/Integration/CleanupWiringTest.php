<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\TestOrder\Test\Integration;

use Hryvinskyi\TestOrder\Api\CleanupConfigInterface;
use Hryvinskyi\TestOrder\Cron\AbandonStaleTestOrders;
use Hryvinskyi\TestOrder\Model\CleanupConfig;
use Magento\Config\Model\Config\Structure;
use Magento\Config\Model\Config\Structure\Element\Field;
use Magento\Config\Model\Config\Structure\Element\Section;
use Magento\Cron\Model\ConfigInterface as CronConfig;
use Magento\Framework\Acl\AclResource\ProviderInterface as AclResources;
use Magento\TestFramework\Helper\Bootstrap;
use PHPUnit\Framework\TestCase;

/**
 * The clean-up of test orders as the shop runs and shows it: the scheduled job the cron reads, the defaults the
 * settings come with, and the admin screen and permission they are edited under.
 */
class CleanupWiringTest extends TestCase
{
    private const string JOB = 'hryvinskyi_test_order_abandon_stale';

    private const string ACL_RESOURCE = 'Hryvinskyi_TestOrder::config';

    /**
     * The cron runs the clean-up job every five minutes, and the container builds it.
     *
     * @magentoAppArea crontab
     * @return void
     */
    public function testTheCleanUpJobIsScheduledEveryFiveMinutes(): void
    {
        $cronConfig = Bootstrap::getObjectManager()->get(CronConfig::class);
        self::assertInstanceOf(CronConfig::class, $cronConfig);
        $group = $cronConfig->getJobs()['default'] ?? null;
        self::assertIsArray($group);
        $job = $group[self::JOB] ?? null;
        self::assertIsArray($job);
        $instance = $job['instance'] ?? null;
        self::assertIsString($instance);

        self::assertSame(
            [AbandonStaleTestOrders::class, 'execute', '*/5 * * * *'],
            [ltrim($instance, '\\'), $job['method'] ?? null, $job['schedule'] ?? null]
        );
        self::assertInstanceOf(
            AbandonStaleTestOrders::class,
            Bootstrap::getObjectManager()->get(AbandonStaleTestOrders::class)
        );
    }

    /**
     * Out of the box a cancelled test order is kept, and one left behind is ended after thirty minutes.
     *
     * @magentoAppArea global
     * @return void
     */
    public function testTheSettingsComeWithTheirDefaults(): void
    {
        $config = Bootstrap::getObjectManager()->get(CleanupConfigInterface::class);
        self::assertInstanceOf(CleanupConfig::class, $config);

        self::assertFalse($config->deletesCancelledTestOrders());
        self::assertSame(30, $config->abandonAfterMinutes());
    }

    /**
     * The settings are edited globally under their own section, guarded by the module's own permission.
     *
     * @magentoAppArea adminhtml
     * @return void
     */
    public function testTheSettingsAreEditedUnderTheirOwnPermission(): void
    {
        $structure = Bootstrap::getObjectManager()->get(Structure::class);
        self::assertInstanceOf(Structure::class, $structure);

        $section = $structure->getElement('test_order');
        self::assertInstanceOf(Section::class, $section);
        self::assertSame(self::ACL_RESOURCE, $section->getAttribute('resource'));

        foreach ([CleanupConfig::XML_PATH_DELETE_CANCELLED, CleanupConfig::XML_PATH_ABANDON_AFTER_MINUTES] as $path) {
            $field = $structure->getElement($path);
            self::assertInstanceOf(Field::class, $field, $path);
            self::assertTrue($field->showInDefault(), $path);
            self::assertFalse($field->showInWebsite(), $path);
        }

        $aclResources = Bootstrap::getObjectManager()->get(AclResources::class);
        self::assertInstanceOf(AclResources::class, $aclResources);
        self::assertContains(self::ACL_RESOURCE, $this->resourceIds($aclResources->getAclResources()));
    }

    /**
     * Every resource id in an ACL tree.
     *
     * @param array<mixed> $tree Resources with their children
     * @return list<string>
     */
    private function resourceIds(array $tree): array
    {
        $ids = [];
        foreach ($tree as $resource) {
            if (!is_array($resource)) {
                continue;
            }
            if (is_string($resource['id'] ?? null)) {
                $ids[] = $resource['id'];
            }
            if (is_array($resource['children'] ?? null)) {
                $ids = [...$ids, ...$this->resourceIds($resource['children'])];
            }
        }

        return $ids;
    }
}
