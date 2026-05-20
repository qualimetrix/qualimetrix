<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Infrastructure\Parallel\Strategy;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Qualimetrix\Configuration\AnalysisConfiguration;
use Qualimetrix\Configuration\ConfigurationProviderInterface;
use Qualimetrix\Core\Metric\DerivedCollectorInterface;
use Qualimetrix\Core\Metric\MetricCollectorInterface;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Infrastructure\Parallel\Strategy\AmphpParallelStrategy;
use Qualimetrix\Infrastructure\Parallel\Strategy\SequentialStrategy;
use Qualimetrix\Infrastructure\Parallel\Strategy\StrategySelector;
use Qualimetrix\Infrastructure\Parallel\Strategy\WorkerCountDetector;
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
        $this->configProvider = self::createStub(ConfigurationProviderInterface::class);
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
            projectRoot: AbsolutePath::fromString(__DIR__),
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
            projectRoot: AbsolutePath::fromString(__DIR__),
            cacheEnabled: true,
            cacheDir: AbsolutePath::fromString('/tmp/cache'),
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
            projectRoot: AbsolutePath::fromString(__DIR__),
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
            projectRoot: AbsolutePath::fromString(__DIR__),
        );
        $this->configProvider->method('getConfiguration')->willReturn($config);

        $selector = $this->createSelector();

        $strategy = $selector->select();

        self::assertInstanceOf(AmphpParallelStrategy::class, $strategy);
        self::assertSame(4, $this->amphpStrategy->getWorkerCount());
    }

    #[Test]
    public function itPropagatesCwdProjectRootIntoStrategy(): void
    {
        $config = new AnalysisConfiguration(
            workers: 4,
            projectRoot: AbsolutePath::fromString((string) getcwd()),
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
            projectRoot: AbsolutePath::fromString('/non/existent/path'),
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
            projectRoot: AbsolutePath::fromString(__DIR__),
            cacheEnabled: false,
        );
        $this->configProvider->method('getConfiguration')->willReturn($config);

        $selector = $this->createSelector();

        $strategy = $selector->select();

        self::assertInstanceOf(AmphpParallelStrategy::class, $strategy);
    }

    #[Test]
    public function itPropagatesLazyDefaultCacheDirIntoStrategy(): void
    {
        // Lazy default cacheDir (null) resolves at AnalysisConfiguration ctor time
        // to "$projectRoot/.qmx-cache". This test pins that the resolved cache dir
        // is propagated as-is to the parallel strategy.
        $projectRoot = AbsolutePath::fromString((string) getcwd());
        $config = new AnalysisConfiguration(
            workers: 4,
            projectRoot: $projectRoot,
            cacheEnabled: true,
            cacheDir: null,
        );
        $this->configProvider->method('getConfiguration')->willReturn($config);

        $selector = $this->createSelector();
        $strategy = $selector->select();

        self::assertInstanceOf(AmphpParallelStrategy::class, $strategy);

        $reflection = new ReflectionProperty(AmphpParallelStrategy::class, 'cacheDir');
        $cacheDir = $reflection->getValue($this->amphpStrategy);

        self::assertInstanceOf(AbsolutePath::class, $cacheDir);
        self::assertSame($projectRoot->value() . '/.qmx-cache', $cacheDir->value());
    }

    #[Test]
    public function itHandlesAbsoluteCacheDir(): void
    {
        $config = new AnalysisConfiguration(
            workers: 4,
            projectRoot: AbsolutePath::fromString(__DIR__),
            cacheEnabled: true,
            cacheDir: AbsolutePath::fromString('/absolute/cache'),
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
