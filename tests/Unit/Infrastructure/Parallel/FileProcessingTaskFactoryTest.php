<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Infrastructure\Parallel;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\Cohesion\Contract\LcomCollectionConfiguration;
use Qualimetrix\Analysis\Evidence\Cohesion\Contract\LcomCollectionConfigurationStoreInterface;
use Qualimetrix\Analysis\Evidence\Complexity\ComplexityRule;
use Qualimetrix\Analysis\Evidence\Complexity\CyclomaticComplexityCollector;
use Qualimetrix\Analysis\Evidence\DependencyModel\Extraction\DependencyVisitor;
use Qualimetrix\Analysis\Evidence\Maintainability\MaintainabilityIndexCollector;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Infrastructure\Parallel\FileProcessingTask;
use Qualimetrix\Infrastructure\Parallel\FileProcessingTaskFactory;
use ReflectionProperty;
use stdClass;

#[CoversClass(FileProcessingTaskFactory::class)]
final class FileProcessingTaskFactoryTest extends TestCase
{
    private LcomCollectionConfigurationStoreInterface&Stub $store;

    protected function setUp(): void
    {
        $this->store = self::createStub(LcomCollectionConfigurationStoreInterface::class);
        $this->store->method('current')->willReturn(LcomCollectionConfiguration::defaults());
    }

    #[Test]
    public function itCreatesATaskWithCollectorDerivedCollectorAndRuleMetadata(): void
    {
        $factory = new FileProcessingTaskFactory(
            $this->store,
            DependencyVisitor::class,
            [CyclomaticComplexityCollector::class],
            [MaintainabilityIndexCollector::class],
            [ComplexityRule::class],
        );

        $task = $factory->create(
            AbsolutePath::fromString('/project/src/Subject.php'),
            AbsolutePath::fromString('/project'),
            AbsolutePath::fromString('/project/.qmx-cache'),
        );

        self::assertTrue($factory->hasCollectors());
        self::assertSame([CyclomaticComplexityCollector::class], $this->property($task, 'collectorClasses'));
        self::assertSame([MaintainabilityIndexCollector::class], $this->property($task, 'derivedCollectorClasses'));
        self::assertSame([ComplexityRule::class], $this->property($task, 'ruleClasses'));
    }

    #[Test]
    public function itReadsRuntimeCollectorConfigurationForEveryTaskCreation(): void
    {
        $store = $this->createMock(LcomCollectionConfigurationStoreInterface::class);
        $store->expects($this->exactly(2))
            ->method('current')
            ->willReturnOnConsecutiveCalls(
                new LcomCollectionConfiguration(['first']),
                new LcomCollectionConfiguration(['second']),
            );
        $factory = new FileProcessingTaskFactory($store, DependencyVisitor::class);

        $first = $factory->create(AbsolutePath::fromString('/project/First.php'), AbsolutePath::fromString('/project'), null);
        $second = $factory->create(AbsolutePath::fromString('/project/Second.php'), AbsolutePath::fromString('/project'), null);

        self::assertSame(['first'], $this->property($first, 'lcomConfiguration')->excludedMethods);
        self::assertSame(['second'], $this->property($second, 'lcomConfiguration')->excludedMethods);
    }

    #[Test]
    public function itCarriesTheTraversalParticipantClassIntoEveryTask(): void
    {
        $factory = new FileProcessingTaskFactory($this->store, DependencyVisitor::class);

        $first = $factory->create(AbsolutePath::fromString('/project/First.php'), AbsolutePath::fromString('/project'), null);
        $second = $factory->create(AbsolutePath::fromString('/project/Second.php'), AbsolutePath::fromString('/project'), null);

        self::assertSame(DependencyVisitor::class, $this->property($first, 'dependencyTraversalParticipantClass'));
        self::assertSame(DependencyVisitor::class, $this->property($second, 'dependencyTraversalParticipantClass'));
    }

    #[Test]
    public function itRejectsAnInvalidTraversalParticipantClass(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must implement');

        /** @phpstan-ignore argument.type */
        new FileProcessingTaskFactory($this->store, stdClass::class);
    }

    private function property(FileProcessingTask $task, string $name): mixed
    {
        return (new ReflectionProperty(FileProcessingTask::class, $name))->getValue($task);
    }
}
