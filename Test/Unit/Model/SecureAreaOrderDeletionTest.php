<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\TestOrder\Test\Unit\Model;

use Hryvinskyi\TestOrder\Model\SecureAreaOrderDeletion;
use Magento\Framework\Exception\CouldNotDeleteException;
use Magento\Framework\Registry;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use PHPUnit\Framework\TestCase;

/**
 * An order is deleted with the secure-area flag up for that one delete and no longer: what the registry held before
 * is what it holds afterwards, whether the delete worked or threw. The registry is the real one, because the rule
 * here is exactly how it treats a key that exists and one that does not.
 */
class SecureAreaOrderDeletionTest extends TestCase
{
    private const string SECURE_AREA = 'isSecureArea';

    /**
     * @var Registry
     */
    private Registry $registry;

    /**
     * @inheritDoc
     */
    protected function setUp(): void
    {
        $this->registry = new Registry();
    }

    /**
     * With no flag set, it is up during the delete and gone afterwards.
     *
     * @return void
     */
    public function testAnUnsetFlagIsUpDuringTheDeleteAndGoneAfterwards(): void
    {
        $this->deletion($this->repositoryThatDeletes())->delete($this->createStub(OrderInterface::class));

        $this->assertNoFlagIsLeft();
    }

    /**
     * With a flag already set, it is up during the delete and holds its old value afterwards.
     *
     * @dataProvider earlierValues
     * @param bool|string $earlier Value the registry held
     * @return void
     */
    public function testASetFlagIsUpDuringTheDeleteAndBackToItsValueAfterwards(bool|string $earlier): void
    {
        $this->registry->register(self::SECURE_AREA, $earlier);

        $this->deletion($this->repositoryThatDeletes())->delete($this->createStub(OrderInterface::class));

        self::assertSame($earlier, $this->registry->registry(self::SECURE_AREA));
    }

    /**
     * Values a flag can hold before the delete, the false a test harness leaves behind among them.
     *
     * @return array<string, array{bool|string}>
     */
    public static function earlierValues(): array
    {
        return [
            'true' => [true],
            'false' => [false],
            'another value' => ['yes'],
        ];
    }

    /**
     * A delete that throws leaves no flag behind and passes its failure on.
     *
     * @return void
     */
    public function testAFailedDeleteLeavesNoFlagBehind(): void
    {
        $failure = new CouldNotDeleteException(__('The order is locked.'));

        try {
            $this->deletion($this->repositoryThatFails($failure))->delete($this->createStub(OrderInterface::class));
            self::fail('The failure of the delete was swallowed.');
        } catch (CouldNotDeleteException $caught) {
            self::assertSame($failure, $caught);
        }

        $this->assertNoFlagIsLeft();
    }

    /**
     * A delete that throws puts back the value the flag held.
     *
     * @return void
     */
    public function testAFailedDeleteRestoresTheEarlierValue(): void
    {
        $this->registry->register(self::SECURE_AREA, false);
        $failure = new CouldNotDeleteException(__('The order is locked.'));

        try {
            $this->deletion($this->repositoryThatFails($failure))->delete($this->createStub(OrderInterface::class));
            self::fail('The failure of the delete was swallowed.');
        } catch (CouldNotDeleteException $caught) {
            self::assertSame($failure, $caught);
        }

        self::assertFalse($this->registry->registry(self::SECURE_AREA));
    }

    /**
     * The order handed in is the one deleted.
     *
     * @return void
     */
    public function testTheGivenOrderIsDeleted(): void
    {
        $order = $this->createStub(OrderInterface::class);
        $repository = $this->createMock(OrderRepositoryInterface::class);
        $repository->expects(self::once())->method('delete')->with($order)->willReturn(true);

        $this->deletion($repository)->delete($order);
    }

    /**
     * Assert the registry holds no flag: reading it finds nothing, and registering it does not throw.
     *
     * @return void
     */
    private function assertNoFlagIsLeft(): void
    {
        self::assertNull($this->registry->registry(self::SECURE_AREA));

        $this->registry->register(self::SECURE_AREA, 'free');
        self::assertSame('free', $this->registry->registry(self::SECURE_AREA));
    }

    /**
     * A repository that deletes once, asserting the flag is up while it does.
     *
     * @return OrderRepositoryInterface
     */
    private function repositoryThatDeletes(): OrderRepositoryInterface
    {
        $repository = $this->createMock(OrderRepositoryInterface::class);
        $repository->expects(self::once())->method('delete')->willReturnCallback(function (): bool {
            self::assertTrue($this->registry->registry(self::SECURE_AREA));

            return true;
        });

        return $repository;
    }

    /**
     * A repository whose delete throws, asserting the flag is up when it does.
     *
     * @param CouldNotDeleteException $failure What the delete throws
     * @return OrderRepositoryInterface
     */
    private function repositoryThatFails(CouldNotDeleteException $failure): OrderRepositoryInterface
    {
        $repository = $this->createMock(OrderRepositoryInterface::class);
        $repository->expects(self::once())->method('delete')->willReturnCallback(
            function () use ($failure): never {
                self::assertTrue($this->registry->registry(self::SECURE_AREA));

                throw $failure;
            }
        );

        return $repository;
    }

    /**
     * The deletion under test, over the real registry.
     *
     * @param OrderRepositoryInterface $repository Repository that deletes
     * @return SecureAreaOrderDeletion
     */
    private function deletion(OrderRepositoryInterface $repository): SecureAreaOrderDeletion
    {
        return new SecureAreaOrderDeletion($repository, $this->registry);
    }
}
