<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Parallel;

use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyTraversalParticipantInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\CollectorRuntimeConfigurableInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\CollectorRuntimeConfiguration;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\DerivedCollectorInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\FileMeasurementCollectorInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricCollectorInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\ParallelSafeCollectorInterface;
use Qualimetrix\Analysis\Run\Contract\Collection\FileProcessorInterface;
use Qualimetrix\Baseline\Suppression\RuleValidatorMapFactory;
use Qualimetrix\Baseline\Suppression\ThresholdOverrideExtractor;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Core\Rule\RuleInterface;
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
    private const string FILE_MEASUREMENT_COLLECTOR_CLASS = 'Qualimetrix\\Analysis\\Evidence\\Measurement\\FileMeasurement\\CompositeCollector';
    private const string FILE_PROCESSOR_CLASS = 'Qualimetrix\\Analysis\\Run\\Collection\\FileProcessor';

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
     * @param AbsolutePath $projectRoot Project root directory
     * @param list<class-string<MetricCollectorInterface>> $collectorClasses Collector class names from DI
     * @param string $dependencyTraversalParticipantClass Validated class-string at the worker trust boundary
     * @param list<class-string<DerivedCollectorInterface>> $derivedCollectorClasses Derived collector class names
     * @param AbsolutePath|null $cacheDir Cache directory (null to disable caching)
     * @param array<string, mixed> $collectorConfig Collector-level configuration (e.g., LCOM exclude methods)
     * @param list<class-string<RuleInterface>> $ruleClasses Rule class names (worker rebuilds threshold-override validator map)
     */
    public static function getFileProcessor(
        AbsolutePath $projectRoot,
        array $collectorClasses,
        string $dependencyTraversalParticipantClass,
        array $derivedCollectorClasses = [],
        ?AbsolutePath $cacheDir = null,
        array $collectorConfig = [],
        array $ruleClasses = [],
    ): FileProcessorInterface {
        $dependencyTraversalParticipantClass = self::validateDependencyTraversalParticipantClass(
            $dependencyTraversalParticipantClass,
        );
        $runtimeConfiguration = CollectorRuntimeConfiguration::fromPayload($collectorConfig);
        $normalizedPayload = $runtimeConfiguration->toPayload();
        $newCacheKey = self::buildCacheKey(
            $projectRoot,
            $collectorClasses,
            $dependencyTraversalParticipantClass,
            $derivedCollectorClasses,
            $cacheDir,
            $normalizedPayload,
            $ruleClasses,
        );

        // Return cached processor if configuration hasn't changed
        if (self::$processor !== null && self::$cacheKey === $newCacheKey) {
            return self::$processor;
        }

        // Create new processor
        self::$processor = self::createFileProcessor(
            $projectRoot,
            $collectorClasses,
            $dependencyTraversalParticipantClass,
            $runtimeConfiguration,
            $derivedCollectorClasses,
            $cacheDir,
            $ruleClasses,
        );
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
     * @param class-string<DependencyTraversalParticipantInterface> $dependencyTraversalParticipantClass
     * @param list<class-string<DerivedCollectorInterface>> $derivedCollectorClasses
     * @param array<string, mixed> $collectorConfig
     * @param list<class-string<RuleInterface>> $ruleClasses
     */
    private static function buildCacheKey(
        AbsolutePath $projectRoot,
        array $collectorClasses,
        string $dependencyTraversalParticipantClass,
        array $derivedCollectorClasses,
        ?AbsolutePath $cacheDir,
        array $collectorConfig = [],
        array $ruleClasses = [],
    ): string {
        // Include collector and rule classes in cache key to detect changes.
        // Sort each list so a permutation of the same set produces an identical
        // hash — DI tag iteration is deterministic within a process, but the
        // cache should not depend on registration order across processes.
        $sortedCollectors = $collectorClasses;
        sort($sortedCollectors);
        $sortedDerived = $derivedCollectorClasses;
        sort($sortedDerived);
        $sortedRules = $ruleClasses;
        sort($sortedRules);

        $collectorsHash = md5(implode('|', $sortedCollectors) . '||' . implode('|', $sortedDerived));
        $rulesHash = md5(implode('|', $sortedRules));
        $configHash = md5(serialize($collectorConfig));

        return $projectRoot->value()
            . '|' . ($cacheDir?->value() ?? 'no-cache')
            . '|' . $collectorsHash
            . '|' . $rulesHash
            . '|' . $configHash
            . '|' . $dependencyTraversalParticipantClass;
    }

    /**
     * Creates a new FileProcessor with collectors from passed class names.
     *
     * @param list<class-string<MetricCollectorInterface>> $collectorClasses
     * @param class-string<DependencyTraversalParticipantInterface> $dependencyTraversalParticipantClass
     * @param list<class-string<DerivedCollectorInterface>> $derivedCollectorClasses
     * @param list<class-string<RuleInterface>> $ruleClasses
     */
    private static function createFileProcessor(
        AbsolutePath $projectRoot,
        array $collectorClasses,
        string $dependencyTraversalParticipantClass,
        CollectorRuntimeConfiguration $runtimeConfiguration,
        array $derivedCollectorClasses,
        ?AbsolutePath $cacheDir,
        array $ruleClasses = [],
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
        $collectors = self::instantiateCollectors($collectorClasses, $runtimeConfiguration);
        $derivedCollectors = self::instantiateDerivedCollectors($derivedCollectorClasses, $runtimeConfiguration);

        /** @var DependencyTraversalParticipantInterface $dependencyTraversalParticipant */
        $dependencyTraversalParticipant = new $dependencyTraversalParticipantClass();
        $fileMeasurementCollectorClass = self::validatedImplementationClass(
            self::FILE_MEASUREMENT_COLLECTOR_CLASS,
            FileMeasurementCollectorInterface::class,
        );
        $compositeCollector = new $fileMeasurementCollectorClass(
            $collectors,
            $derivedCollectors,
            $dependencyTraversalParticipant,
        );

        // Build per-rule threshold-override validator map (static lookup, no DI)
        $validators = RuleValidatorMapFactory::build($ruleClasses);
        $extractor = new ThresholdOverrideExtractor($validators);

        $fileProcessorClass = self::validatedImplementationClass(
            self::FILE_PROCESSOR_CLASS,
            FileProcessorInterface::class,
        );
        $processor = new $fileProcessorClass(
            parser: $parser,
            collector: $compositeCollector,
            thresholdOverrideExtractor: $extractor,
        );
        $processor->setProjectRoot($projectRoot);

        return $processor;
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $contract
     *
     * @return class-string<T>
     */
    private static function validatedImplementationClass(string $implementation, string $contract): string
    {
        if (!is_a($implementation, $contract, true)) {
            throw new RuntimeException(\sprintf('%s must implement %s', $implementation, $contract));
        }

        return $implementation;
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
    private static function instantiateCollectors(
        array $classNames,
        CollectorRuntimeConfiguration $runtimeConfiguration,
    ): array {
        $collectors = [];

        foreach ($classNames as $className) {
            if (!self::canInstantiate($className)) {
                continue;
            }

            /** @var MetricCollectorInterface $collector */
            $collector = new $className();
            self::applyRuntimeConfiguration($collector, $runtimeConfiguration);
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
    private static function instantiateDerivedCollectors(
        array $classNames,
        CollectorRuntimeConfiguration $runtimeConfiguration,
    ): array {
        $collectors = [];

        foreach ($classNames as $className) {
            if (!self::canInstantiate($className)) {
                continue;
            }

            /** @var DerivedCollectorInterface $collector */
            $collector = new $className();
            self::applyRuntimeConfiguration($collector, $runtimeConfiguration);
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

    private static function applyRuntimeConfiguration(
        object $collector,
        CollectorRuntimeConfiguration $configuration,
    ): void {
        if ($collector instanceof CollectorRuntimeConfigurableInterface) {
            $collector->applyRuntimeConfiguration($configuration);
        }
    }

    /** @return class-string<DependencyTraversalParticipantInterface> */
    private static function validateDependencyTraversalParticipantClass(string $className): string
    {
        if ($className === '') {
            throw new RuntimeException('WorkerBootstrap: dependency traversal participant class must not be empty.');
        }

        if (!class_exists($className)) {
            throw new RuntimeException(\sprintf(
                "WorkerBootstrap: dependency traversal participant class '%s' does not exist.",
                $className,
            ));
        }

        if (!is_subclass_of($className, DependencyTraversalParticipantInterface::class)) {
            throw new RuntimeException(\sprintf(
                "WorkerBootstrap: dependency traversal participant class '%s' must implement %s.",
                $className,
                DependencyTraversalParticipantInterface::class,
            ));
        }

        return $className;
    }
}
