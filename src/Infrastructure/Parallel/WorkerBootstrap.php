<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Parallel;

use Qualimetrix\Analysis\Collection\Dependency\DependencyResolver;
use Qualimetrix\Analysis\Collection\Dependency\DependencyVisitor;
use Qualimetrix\Analysis\Collection\FileProcessor;
use Qualimetrix\Analysis\Collection\FileProcessorInterface;
use Qualimetrix\Analysis\Collection\Metric\CompositeCollector;
use Qualimetrix\Core\Metric\CollectorConfigHolder;
use Qualimetrix\Core\Metric\DerivedCollectorInterface;
use Qualimetrix\Core\Metric\MetricCollectorInterface;
use Qualimetrix\Core\Metric\ParallelSafeCollectorInterface;
use Qualimetrix\Infrastructure\Ast\CachedFileParser;
use Qualimetrix\Infrastructure\Ast\PhpFileParser;
use Qualimetrix\Infrastructure\Cache\CacheKeyGenerator;
use Qualimetrix\Infrastructure\Cache\FileCache;
use RuntimeException;

/**
 * Bootstrap for worker processes.
 *
 * Creates and caches a FileProcessor for use in parallel workers.
 * Uses static properties to persist the processor between task executions
 * in the same worker, avoiding repeated initialization overhead.
 *
 * The bootstrap creates a minimal set of services without the full DI container:
 * - PhpFileParser (with optional caching)
 * - CompositeCollector with collectors from passed class names
 * - FileProcessor to orchestrate parsing and collection
 *
 * Collector classes are passed from the main process to ensure workers
 * use the same set of collectors as configured in the DI container.
 */
final class WorkerBootstrap
{
    /**
     * Cached FileProcessor instance (static for persistence across tasks).
     */
    private static ?FileProcessorInterface $processor = null;

    /**
     * Cache key based on configuration (to detect changes).
     */
    private static ?string $cacheKey = null;

    /**
     * Gets or creates a FileProcessor for the given configuration.
     *
     * The processor is cached and reused for subsequent calls with the same
     * configuration. If configuration changes, a new processor is created.
     *
     * @param string $projectRoot Project root directory
     * @param list<class-string<MetricCollectorInterface>> $collectorClasses Collector class names from DI
     * @param list<class-string<DerivedCollectorInterface>> $derivedCollectorClasses Derived collector class names
     * @param string|null $cacheDir Cache directory (null to disable caching)
     * @param array<string, mixed> $collectorConfig Collector-level configuration (e.g., LCOM exclude methods)
     */
    public static function getFileProcessor(
        string $projectRoot,
        array $collectorClasses,
        array $derivedCollectorClasses = [],
        ?string $cacheDir = null,
        array $collectorConfig = [],
    ): FileProcessorInterface {
        $newCacheKey = self::buildCacheKey($projectRoot, $collectorClasses, $derivedCollectorClasses, $cacheDir, $collectorConfig);

        // Return cached processor if configuration hasn't changed
        if (self::$processor !== null && self::$cacheKey === $newCacheKey) {
            // Apply collector config (must be set on each call, not just on cache miss,
            // because static state resets between worker process reuse scenarios)
            foreach ($collectorConfig as $key => $value) {
                CollectorConfigHolder::set($key, $value);
            }

            return self::$processor;
        }

        // Create new processor
        self::$processor = self::createFileProcessor($projectRoot, $collectorClasses, $derivedCollectorClasses, $cacheDir);
        self::$cacheKey = $newCacheKey;

        // Apply collector config
        foreach ($collectorConfig as $key => $value) {
            CollectorConfigHolder::set($key, $value);
        }

        return self::$processor;
    }

    /**
     * Resets the cached processor (useful for testing).
     */
    public static function reset(): void
    {
        self::$processor = null;
        self::$cacheKey = null;
    }

    /**
     * Builds a unique cache key for the configuration.
     *
     * @param list<class-string<MetricCollectorInterface>> $collectorClasses
     * @param list<class-string<DerivedCollectorInterface>> $derivedCollectorClasses
     * @param array<string, mixed> $collectorConfig
     */
    private static function buildCacheKey(
        string $projectRoot,
        array $collectorClasses,
        array $derivedCollectorClasses,
        ?string $cacheDir,
        array $collectorConfig = [],
    ): string {
        // Include collector classes in cache key to detect changes
        $collectorsHash = md5(implode('|', $collectorClasses) . '||' . implode('|', $derivedCollectorClasses));
        $sortedConfig = $collectorConfig;
        ksort($sortedConfig);
        $configHash = $sortedConfig !== [] ? md5(serialize($sortedConfig)) : '';

        return $projectRoot . '|' . ($cacheDir ?? 'no-cache') . '|' . $collectorsHash . '|' . $configHash;
    }

    /**
     * Creates a new FileProcessor with collectors from passed class names.
     *
     * @param list<class-string<MetricCollectorInterface>> $collectorClasses
     * @param list<class-string<DerivedCollectorInterface>> $derivedCollectorClasses
     */
    private static function createFileProcessor(
        string $projectRoot,
        array $collectorClasses,
        array $derivedCollectorClasses,
        ?string $cacheDir,
    ): FileProcessorInterface {
        // Create parser (with optional caching)
        $baseParser = new PhpFileParser();

        if ($cacheDir !== null) {
            $cache = new FileCache($cacheDir);
            $keyGenerator = new CacheKeyGenerator();
            $parser = new CachedFileParser($baseParser, $cache, $keyGenerator);
        } else {
            $parser = $baseParser;
        }

        // Create collectors from class names
        $collectors = self::instantiateCollectors($collectorClasses);
        $derivedCollectors = self::instantiateDerivedCollectors($derivedCollectorClasses);

        $compositeCollector = new CompositeCollector($collectors, $derivedCollectors);

        // Create and set dependency visitor for unified AST traversal
        $dependencyVisitor = new DependencyVisitor(new DependencyResolver());
        $compositeCollector->setDependencyVisitor($dependencyVisitor);

        return new FileProcessor($parser, $compositeCollector);
    }

    /**
     * Instantiates collectors from class names.
     *
     * Validates that each class exists and has no required constructor parameters.
     * Collectors with required dependencies are skipped with a warning to stderr,
     * since workers cannot perform dependency injection.
     *
     * @param list<class-string<MetricCollectorInterface>> $classNames
     *
     * @return list<MetricCollectorInterface>
     */
    private static function instantiateCollectors(array $classNames): array
    {
        $collectors = [];

        foreach ($classNames as $className) {
            if (!self::canInstantiate($className)) {
                continue;
            }

            /** @var MetricCollectorInterface $collector */
            $collector = new $className();
            $collectors[] = $collector;
        }

        return $collectors;
    }

    /**
     * Instantiates derived collectors from class names.
     *
     * Validates that each class exists and has no required constructor parameters.
     * Collectors with required dependencies are skipped with a warning to stderr,
     * since workers cannot perform dependency injection.
     *
     * @param list<class-string<DerivedCollectorInterface>> $classNames
     *
     * @return list<DerivedCollectorInterface>
     */
    private static function instantiateDerivedCollectors(array $classNames): array
    {
        $collectors = [];

        foreach ($classNames as $className) {
            if (!self::canInstantiate($className)) {
                continue;
            }

            /** @var DerivedCollectorInterface $collector */
            $collector = new $className();
            $collectors[] = $collector;
        }

        return $collectors;
    }

    /**
     * Checks if a collector class can be safely instantiated in a parallel worker.
     *
     * Only collectors implementing ParallelSafeCollectorInterface are allowed.
     * This provides a compile-time contract instead of runtime reflection.
     *
     * @param class-string $className
     */
    private static function canInstantiate(string $className): bool
    {
        if (!class_exists($className)) {
            throw new RuntimeException(\sprintf(
                "WorkerBootstrap: class '%s' does not exist. This indicates a misconfigured collector.",
                $className,
            ));
        }

        if (!is_subclass_of($className, ParallelSafeCollectorInterface::class)) {
            fwrite(\STDERR, \sprintf(
                "[WorkerBootstrap] WARNING: collector '%s' does not implement ParallelSafeCollectorInterface "
                . "and will be SKIPPED in parallel mode. Run with --workers=0 for complete results, "
                . "or implement ParallelSafeCollectorInterface if the collector has no required dependencies.\n",
                $className,
            ));

            return false;
        }

        return true;
    }
}
