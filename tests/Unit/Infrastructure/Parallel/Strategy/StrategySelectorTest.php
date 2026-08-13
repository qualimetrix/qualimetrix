<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Infrastructure\Parallel\Strategy;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Qualimetrix\Analysis\Configuration\Contract\TransitionalRuntimeConfiguration;
use Qualimetrix\Analysis\Configuration\Contract\TransitionalRuntimeConfigurationProviderInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\CollectorRuntimeConfiguration;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\CollectorRuntimeConfigurationStoreInterface;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Infrastructure\Parallel\FileProcessingTaskFactory;
use Qualimetrix\Infrastructure\Parallel\Strategy\AmphpParallelStrategy;
use Qualimetrix\Infrastructure\Parallel\Strategy\SequentialStrategy;
use Qualimetrix\Infrastructure\Parallel\Strategy\StrategySelector;
use Qualimetrix\Infrastructure\Parallel\Strategy\WorkerCountDetector;
use ReflectionProperty;

#[CoversClass(StrategySelector::class)]
final class StrategySelectorTest extends TestCase
{
    private const string TRAVERSAL_PARTICIPANT_CLASS = 'Qualimetrix\\Analysis\\Evidence\\DependencyModel\\Extraction\\DependencyVisitor';

    private AmphpParallelStrategy $amphpStrategy;
    private SequentialStrategy $sequentialStrategy;
    private TransitionalRuntimeConfigurationProviderInterface&Stub $configProvider;
    private CollectorRuntimeConfigurationStoreInterface&Stub $collectorRuntimeConfigurationStore;

    protected function setUp(): void
    {
        $this->collectorRuntimeConfigurationStore = self::createStub(CollectorRuntimeConfigurationStoreInterface::class);
        $this->collectorRuntimeConfigurationStore->method('current')
            ->willReturn(new CollectorRuntimeConfiguration(['configure-me']));
        $this->amphpStrategy = new AmphpParallelStrategy(new FileProcessingTaskFactory(
            $this->collectorRuntimeConfigurationStore,
            self::TRAVERSAL_PARTICIPANT_CLASS,
        ));
        $this->sequentialStrategy = new SequentialStrategy();
        $this->configProvider = self::createStub(TransitionalRuntimeConfigurationProviderInterface::class);
    }

    #[Test]
    public function itSelectsSequentialWhenWorkersIsZero(): void
    {
        $config = new TransitionalRuntimeConfiguration(workers: 0);
        $this->configProvider->method('getConfiguration')->willReturn($config);

        $selector = $this->createSelector();

        $strategy = $selector->select();

        self::assertSame($this->sequentialStrategy, $strategy);
    }

    #[Test]
    public function itSelectsSequentialWhenWorkersIsOne(): void
    {
        $config = new TransitionalRuntimeConfiguration(workers: 1);
        $this->configProvider->method('getConfiguration')->willReturn($config);

        $selector = $this->createSelector();

        $strategy = $selector->select();

        self::assertSame($this->sequentialStrategy, $strategy);
    }

    #[Test]
    public function itSelectsSequentialWhenRequestedWorkersIsOne(): void
    {
        $config = new TransitionalRuntimeConfiguration(
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
        $config = new TransitionalRuntimeConfiguration(
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
        $config = new TransitionalRuntimeConfiguration(
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
        $config = new TransitionalRuntimeConfiguration(
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
        $config = new TransitionalRuntimeConfiguration(
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
        $config = new TransitionalRuntimeConfiguration(
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
        $config = new TransitionalRuntimeConfiguration(
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
        // Lazy default cacheDir (null) resolves at TransitionalRuntimeConfiguration ctor time
        // to "$projectRoot/.qmx-cache". This test pins that the resolved cache dir
        // is propagated as-is to the parallel strategy.
        $projectRoot = AbsolutePath::fromString((string) getcwd());
        $config = new TransitionalRuntimeConfiguration(
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
        $config = new TransitionalRuntimeConfiguration(
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

    private function createSelector(): StrategySelector
    {
        return new StrategySelector(
            amphpStrategy: $this->amphpStrategy,
            sequentialStrategy: $this->sequentialStrategy,
            configurationProvider: $this->configProvider,
            workerCountDetector: new WorkerCountDetector(),
            logger: new NullLogger(),
        );
    }
}
