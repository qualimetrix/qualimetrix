<?php

declare(strict_types=1);

namespace AiMessDetector\Infrastructure\Parallel;

use AiMessDetector\Analysis\Collection\Dependency\DependencyResolver;
use AiMessDetector\Analysis\Collection\Dependency\DependencyVisitor;
use AiMessDetector\Analysis\Collection\FileProcessor;
use AiMessDetector\Analysis\Collection\FileProcessorInterface;
use AiMessDetector\Analysis\Collection\Metric\CompositeCollector;
use AiMessDetector\Core\Metric\DerivedCollectorInterface;
use AiMessDetector\Core\Metric\MetricCollectorInterface;
use AiMessDetector\Infrastructure\Ast\CachedFileParser;
use AiMessDetector\Infrastructure\Ast\PhpFileParser;
use AiMessDetector\Infrastructure\Cache\CacheKeyGenerator;
use AiMessDetector\Infrastructure\Cache\FileCache;
use ReflectionClass;

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
     */
    public static function getFileProcessor(
        string $projectRoot,
        array $collectorClasses,
        array $derivedCollectorClasses = [],
        ?string $cacheDir = null,
    ): FileProcessorInterface {
        $newCacheKey = self::buildCacheKey($projectRoot, $collectorClasses, $derivedCollectorClasses, $cacheDir);

        // Return cached processor if configuration hasn't changed
        if (self::$processor !== null && self::$cacheKey === $newCacheKey) {
            return self::$processor;
        }

        // Create new processor
        self::$processor = self::createFileProcessor($projectRoot, $collectorClasses, $derivedCollectorClasses, $cacheDir);
        self::$cacheKey = $newCacheKey;

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
     */
    private static function buildCacheKey(
        string $projectRoot,
        array $collectorClasses,
        array $derivedCollectorClasses,
        ?string $cacheDir,
    ): string {
        // Include collector classes in cache key to detect changes
        $collectorsHash = md5(implode('|', $collectorClasses) . '||' . implode('|', $derivedCollectorClasses));

        return $projectRoot . '|' . ($cacheDir ?? 'no-cache') . '|' . $collectorsHash;
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
     * Checks if a class can be safely instantiated without constructor arguments.
     *
     * @param class-string $className
     */
    private static function canInstantiate(string $className): bool
    {
        if (!class_exists($className)) {
            fwrite(\STDERR, \sprintf(
                "[WorkerBootstrap] Warning: class '%s' does not exist, skipping.\n",
                $className,
            ));

            return false;
        }

        $reflection = new ReflectionClass($className);
        $constructor = $reflection->getConstructor();

        if ($constructor !== null) {
            foreach ($constructor->getParameters() as $param) {
                if (!$param->isOptional()) {
                    fwrite(\STDERR, \sprintf(
                        "[WorkerBootstrap] Warning: class '%s' has required constructor parameter '%s' and cannot be instantiated in a worker without DI. Skipping.\n",
                        $className,
                        $param->getName(),
                    ));

                    return false;
                }
            }
        }

        return true;
    }
}
