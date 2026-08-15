<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Infrastructure\Parallel\Unit\Strategy;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Qualimetrix\Analysis\Evidence\Cohesion\Runtime\LcomCollectionConfigurationStore;
use Qualimetrix\Analysis\Evidence\DependencyModel\Extraction\DependencyVisitor;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Core\Profiler\Contract\ProfilerInterface;
use Qualimetrix\Infrastructure\Cache\CacheConfigurationStore;
use Qualimetrix\Infrastructure\Cache\Contract\CacheConfiguration;
use Qualimetrix\Infrastructure\Parallel\Contract\ParallelConfiguration;
use Qualimetrix\Infrastructure\Parallel\FileProcessingTaskFactory;
use Qualimetrix\Infrastructure\Parallel\Runtime\ParallelConfigurationStore;
use Qualimetrix\Infrastructure\Parallel\Strategy\AmphpParallelStrategy;
use Qualimetrix\Infrastructure\Parallel\Strategy\SequentialStrategy;
use Qualimetrix\Infrastructure\Parallel\Strategy\StrategySelector;
use Qualimetrix\Infrastructure\Parallel\Strategy\WorkerCountDetector;
use ReflectionProperty;

#[CoversClass(StrategySelector::class)]
final class StrategySelectorTest extends TestCase
{
    #[Test]
    public function itSelectsSequentialForZeroWorkers(): void
    {
        [$selector, , $sequential] = $this->selector(0);

        self::assertSame($sequential, $selector->select(AbsolutePath::fromString(__DIR__)));
    }

    #[Test]
    public function itSelectsSequentialForOneWorker(): void
    {
        [$selector, , $sequential] = $this->selector(1);

        self::assertSame($sequential, $selector->select(AbsolutePath::fromString(__DIR__)));
    }

    #[Test]
    public function itSelectsAndConfiguresParallelForAnExplicitWorkerCount(): void
    {
        [$selector, $parallel] = $this->selector(4);

        self::assertSame($parallel, $selector->select(AbsolutePath::fromString(__DIR__)));
        self::assertSame(4, $parallel->getWorkerCount());
    }

    #[Test]
    public function itFallsBackToSequentialForAMissingProjectRoot(): void
    {
        [$selector, , $sequential] = $this->selector(4);

        self::assertSame($sequential, $selector->select(AbsolutePath::fromString('/non/existent/qmx-root')));
    }

    #[Test]
    public function itPassesTheOwnerCacheConfigurationToParallelWorkers(): void
    {
        $cacheDir = AbsolutePath::fromString('/tmp/qmx-parallel-cache');
        [$selector, $parallel] = $this->selector(4, new CacheConfiguration($cacheDir, true));

        self::assertSame($parallel, $selector->select(AbsolutePath::fromString(__DIR__)));

        $property = new ReflectionProperty(AmphpParallelStrategy::class, 'cacheDir');
        $configured = $property->getValue($parallel);
        self::assertInstanceOf(AbsolutePath::class, $configured);
        self::assertSame($cacheDir->value(), $configured->value());
    }

    #[Test]
    public function itDisablesWorkerCacheWhenTheOwnerConfigurationDisablesIt(): void
    {
        [$selector, $parallel] = $this->selector(
            4,
            new CacheConfiguration(AbsolutePath::fromString('/tmp/qmx-parallel-cache'), false),
        );

        self::assertSame($parallel, $selector->select(AbsolutePath::fromString(__DIR__)));
        self::assertNull((new ReflectionProperty(AmphpParallelStrategy::class, 'cacheDir'))->getValue($parallel));
    }

    /**
     * @return array{StrategySelector, AmphpParallelStrategy, SequentialStrategy}
     */
    private function selector(
        ?int $workers,
        ?CacheConfiguration $cache = null,
    ): array {
        $profiler = self::createStub(ProfilerInterface::class);
        $parallel = new AmphpParallelStrategy(new FileProcessingTaskFactory(
            new LcomCollectionConfigurationStore(),
            DependencyVisitor::class,
        ));
        $sequential = new SequentialStrategy($profiler);

        $parallelStore = new ParallelConfigurationStore();
        $parallelStore->replace(new ParallelConfiguration($workers));
        $cacheStore = new CacheConfigurationStore();
        $cacheStore->replace($cache ?? new CacheConfiguration(
            AbsolutePath::fromString(__DIR__ . '/.qmx-cache'),
        ));

        return [
            new StrategySelector(
                $parallel,
                $sequential,
                $parallelStore,
                $cacheStore,
                new WorkerCountDetector(),
                new NullLogger(),
            ),
            $parallel,
            $sequential,
        ];
    }
}
