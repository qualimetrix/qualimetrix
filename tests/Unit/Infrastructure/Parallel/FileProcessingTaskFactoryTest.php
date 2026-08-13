<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Infrastructure\Parallel;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\DependencyModel\Extraction\DependencyVisitor;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\CollectorRuntimeConfiguration;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\CollectorRuntimeConfigurationStoreInterface;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Infrastructure\Parallel\FileProcessingTask;
use Qualimetrix\Infrastructure\Parallel\FileProcessingTaskFactory;
use Qualimetrix\Metrics\Complexity\CyclomaticComplexityCollector;
use Qualimetrix\Metrics\Maintainability\MaintainabilityIndexCollector;
use Qualimetrix\Rules\Complexity\ComplexityRule;
use ReflectionProperty;
use stdClass;

#[CoversClass(FileProcessingTaskFactory::class)]
final class FileProcessingTaskFactoryTest extends TestCase
{
    private CollectorRuntimeConfigurationStoreInterface&Stub $store;

    protected function setUp(): void
    {
        $this->store = self::createStub(CollectorRuntimeConfigurationStoreInterface::class);
        $this->store->method('current')->willReturn(CollectorRuntimeConfiguration::empty());
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
        $store = $this->createMock(CollectorRuntimeConfigurationStoreInterface::class);
        $store->expects($this->exactly(2))
            ->method('current')
            ->willReturnOnConsecutiveCalls(
                new CollectorRuntimeConfiguration(['first']),
                new CollectorRuntimeConfiguration(['second']),
            );
        $factory = new FileProcessingTaskFactory($store, DependencyVisitor::class);

        $first = $factory->create(AbsolutePath::fromString('/project/First.php'), AbsolutePath::fromString('/project'), null);
        $second = $factory->create(AbsolutePath::fromString('/project/Second.php'), AbsolutePath::fromString('/project'), null);

        self::assertSame(['lcom_excluded_methods' => ['first']], $this->property($first, 'collectorConfig'));
        self::assertSame(['lcom_excluded_methods' => ['second']], $this->property($second, 'collectorConfig'));
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
