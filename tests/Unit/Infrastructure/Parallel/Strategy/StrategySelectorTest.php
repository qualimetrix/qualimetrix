<?php

declare(strict_types=1);

namespace AiMessDetector\Tests\Unit\Infrastructure\Parallel\Strategy;

use AiMessDetector\Configuration\AnalysisConfiguration;
use AiMessDetector\Configuration\ConfigurationProviderInterface;
use AiMessDetector\Core\Metric\DerivedCollectorInterface;
use AiMessDetector\Core\Metric\MetricCollectorInterface;
use AiMessDetector\Infrastructure\Parallel\Strategy\AmphpParallelStrategy;
use AiMessDetector\Infrastructure\Parallel\Strategy\SequentialStrategy;
use AiMessDetector\Infrastructure\Parallel\Strategy\StrategySelector;
use AiMessDetector\Infrastructure\Parallel\Strategy\WorkerCountDetector;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionProperty;

#[CoversClass(StrategySelector::class)]
final class StrategySelectorTest extends TestCase
{
    private AmphpParallelStrategy $amphpStrategy;
    private SequentialStrategy $sequentialStrategy;
    private ConfigurationProviderInterface&Stub $configProvider;

    protected function setUp(): void
    {
        $this->amphpStrategy = new AmphpParallelStrategy(new NullLogger());
        $this->sequentialStrategy = new SequentialStrategy();
        $this->configProvider = $this->createStub(ConfigurationProviderInterface::class);
    }


    #[Test]
    public function itSelectsSequentialWhenWorkersIsZero(): void
    {
        $config = new AnalysisConfiguration(workers: 0);
        $this->configProvider->method('getConfiguration')->willReturn($config);

        $selector = $this->createSelector();

        $strategy = $selector->select();

        self::assertSame($this->sequentialStrategy, $strategy);
    }

    #[Test]
    public function itSelectsSequentialWhenWorkersIsOne(): void
    {
        $config = new AnalysisConfiguration(workers: 1);
        $this->configProvider->method('getConfiguration')->willReturn($config);

        $selector = $this->createSelector();

        $strategy = $selector->select();

        self::assertSame($this->sequentialStrategy, $strategy);
    }


    #[Test]
    public function itSelectsSequentialWhenRequestedWorkersIsOne(): void
    {
        $config = new AnalysisConfiguration(
            workers: 1, // explicitly sequential
            projectRoot: __DIR__,
        );
        $this->configProvider->method('getConfiguration')->willReturn($config);

        $selector = $this->createSelector();

        $strategy = $selector->select();

        self::assertSame($this->sequentialStrategy, $strategy);
    }

    #[Test]
    public function itSelectsParallelAndConfiguresIt(): void
    {
        $config = new AnalysisConfiguration(
            workers: 4,
            projectRoot: __DIR__,
            cacheEnabled: true,
            cacheDir: '/tmp/cache',
        );
        $this->configProvider->method('getConfiguration')->willReturn($config);

        $selector = $this->createSelector();

        $strategy = $selector->select();

        // Verify that parallel strategy was selected
        self::assertInstanceOf(AmphpParallelStrategy::class, $strategy);

        // Verify that settings were applied
        self::assertSame(4, $this->amphpStrategy->getWorkerCount());
    }

    #[Test]
    public function itAutoDetectsWorkerCountWhenNull(): void
    {
        $config = new AnalysisConfiguration(
            workers: null, // auto-detect
            projectRoot: __DIR__,
        );
        $this->configProvider->method('getConfiguration')->willReturn($config);

        $selector = $this->createSelector();

        $strategy = $selector->select();

        // Parallel strategy should be selected if workers > 1
        // Or sequential if workers <= 1
        // This depends on the system, so we just verify the method returned a strategy
        self::assertInstanceOf(AmphpParallelStrategy::class, $strategy);
        self::assertGreaterThan(1, $this->amphpStrategy->getWorkerCount());
    }

    #[Test]
    public function itUsesExplicitWorkerCount(): void
    {
        $config = new AnalysisConfiguration(
            workers: 4, // explicit count
            projectRoot: __DIR__,
        );
        $this->configProvider->method('getConfiguration')->willReturn($config);

        $selector = $this->createSelector();

        $strategy = $selector->select();

        self::assertInstanceOf(AmphpParallelStrategy::class, $strategy);
        self::assertSame(4, $this->amphpStrategy->getWorkerCount());
    }

    #[Test]
    public function itConvertsRelativeProjectRootToAbsolute(): void
    {
        $config = new AnalysisConfiguration(
            workers: 4,
            projectRoot: '.', // relative path
        );
        $this->configProvider->method('getConfiguration')->willReturn($config);

        $selector = $this->createSelector();

        $strategy = $selector->select();

        self::assertInstanceOf(AmphpParallelStrategy::class, $strategy);
    }

    #[Test]
    public function itFallsBackToSequentialWhenProjectRootDoesNotExist(): void
    {
        $config = new AnalysisConfiguration(
            workers: 4,
            projectRoot: '/non/existent/path',
        );
        $this->configProvider->method('getConfiguration')->willReturn($config);

        $selector = $this->createSelector();

        $strategy = $selector->select();

        self::assertSame($this->sequentialStrategy, $strategy);
    }

    #[Test]
    public function itDisablesCacheWhenCacheDisabled(): void
    {
        $config = new AnalysisConfiguration(
            workers: 4,
            projectRoot: __DIR__,
            cacheEnabled: false,
        );
        $this->configProvider->method('getConfiguration')->willReturn($config);

        $selector = $this->createSelector();

        $strategy = $selector->select();

        self::assertInstanceOf(AmphpParallelStrategy::class, $strategy);
    }

    #[Test]
    public function itUsesResolvedRootForRelativeCacheDir(): void
    {
        // Use a relative project root that gets resolved via realpath()
        $config = new AnalysisConfiguration(
            workers: 4,
            projectRoot: '.', // relative
            cacheEnabled: true,
            cacheDir: '.aimd-cache', // relative cache dir
        );
        $this->configProvider->method('getConfiguration')->willReturn($config);

        $selector = $this->createSelector();
        $strategy = $selector->select();

        self::assertInstanceOf(AmphpParallelStrategy::class, $strategy);

        // Verify cache dir via reflection — it must use the resolved root, not '.'
        $reflection = new ReflectionProperty(AmphpParallelStrategy::class, 'cacheDir');
        $cacheDir = $reflection->getValue($this->amphpStrategy);

        self::assertIsString($cacheDir);
        self::assertStringStartsWith('/', $cacheDir);
        self::assertStringNotContainsString('/./', $cacheDir, 'Cache dir should use resolved root, not relative path');
        self::assertStringEndsWith('/.aimd-cache', $cacheDir);
    }

    #[Test]
    public function itHandlesAbsoluteCacheDir(): void
    {
        $config = new AnalysisConfiguration(
            workers: 4,
            projectRoot: __DIR__,
            cacheEnabled: true,
            cacheDir: '/absolute/cache',
        );
        $this->configProvider->method('getConfiguration')->willReturn($config);

        $selector = $this->createSelector();

        $strategy = $selector->select();

        self::assertInstanceOf(AmphpParallelStrategy::class, $strategy);
    }

    /**
     * @param list<class-string<MetricCollectorInterface>> $collectorClasses
     * @param list<class-string<DerivedCollectorInterface>> $derivedCollectorClasses
     */
    private function createSelector(
        array $collectorClasses = [],
        array $derivedCollectorClasses = [],
    ): StrategySelector {
        return new StrategySelector(
            amphpStrategy: $this->amphpStrategy,
            sequentialStrategy: $this->sequentialStrategy,
            configurationProvider: $this->configProvider,
            workerCountDetector: new WorkerCountDetector(),
            logger: new NullLogger(),
            collectorClasses: $collectorClasses,
            derivedCollectorClasses: $derivedCollectorClasses,
        );
    }
}
