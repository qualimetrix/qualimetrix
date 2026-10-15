<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Parallel;

use Qualimetrix\Analysis\Evidence\Cohesion\Contract\LcomCollectionConfigurableInterface;
use Qualimetrix\Analysis\Evidence\Cohesion\Contract\LcomCollectionConfiguration;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyTraversalParticipantInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\DeclarationRegistrarFactory;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\DerivedCollectorInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\FileMeasurementCollectorInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricCollectorInterface;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionDocumentFormsInterface;
use Qualimetrix\Analysis\Policy\Inline\Contract\RuleValidatorMapFactory;
use Qualimetrix\Analysis\Policy\Inline\Contract\SourceControlExtractorInterface;
use Qualimetrix\Analysis\Policy\Inline\Contract\SuppressionExtractor;
use Qualimetrix\Analysis\Policy\Inline\Contract\ThresholdOverrideExtractor;
use Qualimetrix\Analysis\Run\Contract\Collection\FileProcessorInterface;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Infrastructure\Ast\WorkerParserFactory;
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
 *
 * @qmx-threshold coupling.instability warning=0.875001 -- The worker-side composition rebuilds the registered collector, parser and source-control services for its few task consumers; moving the assembly only transfers those required dependencies.
 */
final class WorkerBootstrap
{
    private const string FILE_MEASUREMENT_COLLECTOR_CLASS = 'Qualimetrix\\Analysis\\Evidence\\Measurement\\FileMeasurement\\CompositeCollector';
    private const string FILE_PROCESSOR_CLASS = 'Qualimetrix\\Analysis\\Run\\Collection\\FileProcessor';
    private const string SOURCE_CONTROL_EXTRACTOR_CLASS = 'Qualimetrix\\Analysis\\Policy\\Inline\\Extraction\\SourceControlExtractor';

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
     * @param AbsolutePath|null $cacheDir Cache directory (null to disable caching)
     * @param LcomCollectionConfiguration $lcomConfiguration Exact Cohesion-owned worker configuration
     * @param RuleOptionDocumentFormsInterface $documentForms Finding's serialized document forms service
     */
    public static function getFileProcessor(
        AbsolutePath $projectRoot,
        WorkerComposition $composition,
        RuleOptionDocumentFormsInterface $documentForms,
        ?AbsolutePath $cacheDir = null,
        LcomCollectionConfiguration $lcomConfiguration = new LcomCollectionConfiguration(),
    ): FileProcessorInterface {
        self::initializeWorker();
        WorkerComposition::validateDependencyTraversalParticipantClass(
            $composition->dependencyTraversalParticipantClass,
        );
        $newCacheKey = self::buildCacheKey(
            $projectRoot,
            $composition,
            $documentForms,
            $cacheDir,
            $lcomConfiguration,
        );

        // Return cached processor if configuration hasn't changed
        if (self::$processor !== null && self::$cacheKey === $newCacheKey) {
            return self::$processor;
        }

        // Create new processor
        self::$processor = self::createFileProcessor(
            $projectRoot,
            $composition,
            $documentForms,
            $lcomConfiguration,
            $cacheDir,
        );
        self::$cacheKey = $newCacheKey;

        return self::$processor;
    }

    public static function initializeWorker(): void
    {
        $nonce = getenv('QMX_WORKER_NOTE_NONCE');
        if (\defined('AMP_WORKER') && \is_string($nonce) && $nonce !== '') {
            WorkerFatalNotes::forWorker($nonce);
        }
    }

    /**
     * Resets the cached processor (useful for testing).
     */
    public static function reset(): void
    {
        self::$processor = null;
        self::$cacheKey = null;
    }

    /** Builds a unique cache key for the configuration. */
    private static function buildCacheKey(
        AbsolutePath $projectRoot,
        WorkerComposition $composition,
        RuleOptionDocumentFormsInterface $documentForms,
        ?AbsolutePath $cacheDir,
        LcomCollectionConfiguration $lcomConfiguration = new LcomCollectionConfiguration(),
    ): string {
        // Include collector and rule classes in cache key to detect changes.
        // Sort each list so a permutation of the same set produces an identical
        // hash — DI tag iteration is deterministic within a process, but the
        // cache should not depend on registration order across processes.
        $sortedCollectors = $composition->collectorClasses;
        sort($sortedCollectors);
        $sortedDerived = $composition->derivedCollectorClasses;
        sort($sortedDerived);
        $sortedRules = $composition->ruleClasses;
        sort($sortedRules);

        $collectorsHash = md5(implode('|', $sortedCollectors) . '||' . implode('|', $sortedDerived));
        $rulesHash = md5(implode('|', $sortedRules));
        $configHash = md5(serialize($lcomConfiguration));

        return $projectRoot->value()
            . '|' . ($cacheDir?->value() ?? 'no-cache')
            . '|' . $collectorsHash
            . '|' . $rulesHash
            . '|' . $configHash
            . '|' . md5(serialize($documentForms))
            . '|' . $composition->dependencyTraversalParticipantClass;
    }

    /** Creates a new FileProcessor with collectors from passed class names. */
    private static function createFileProcessor(
        AbsolutePath $projectRoot,
        WorkerComposition $composition,
        RuleOptionDocumentFormsInterface $documentForms,
        LcomCollectionConfiguration $lcomConfiguration,
        ?AbsolutePath $cacheDir,
    ): FileProcessorInterface {
        $parser = WorkerParserFactory::create($cacheDir);

        // Create collectors from class names
        $collectors = self::instantiateCollectors($composition->collectorClasses, $lcomConfiguration);
        $derivedCollectors = self::instantiateDerivedCollectors($composition->derivedCollectorClasses, $lcomConfiguration);

        $dependencyTraversalParticipantClass = $composition->dependencyTraversalParticipantClass;
        /** @var DependencyTraversalParticipantInterface $dependencyTraversalParticipant */
        $dependencyTraversalParticipant = new $dependencyTraversalParticipantClass();
        $fileMeasurementCollectorClass = self::validatedImplementationClass(
            self::FILE_MEASUREMENT_COLLECTOR_CLASS,
            FileMeasurementCollectorInterface::class,
        );
        $compositeCollector = new $fileMeasurementCollectorClass(
            $collectors,
            new DeclarationRegistrarFactory(),
            $derivedCollectors,
            $dependencyTraversalParticipant,
        );

        // Build per-rule threshold-override validator map (static lookup, no DI)
        $validators = RuleValidatorMapFactory::build($documentForms, $composition->ruleClasses);
        $thresholdOverrideExtractor = new ThresholdOverrideExtractor($validators);
        $sourceControlExtractorClass = self::validatedImplementationClass(
            self::SOURCE_CONTROL_EXTRACTOR_CLASS,
            SourceControlExtractorInterface::class,
        );
        $sourceControlExtractor = new $sourceControlExtractorClass(
            new SuppressionExtractor(),
            $thresholdOverrideExtractor,
        );

        $fileProcessorClass = self::validatedImplementationClass(
            self::FILE_PROCESSOR_CLASS,
            FileProcessorInterface::class,
        );
        $processor = new $fileProcessorClass(
            parser: $parser,
            collector: $compositeCollector,
            sourceControlExtractor: $sourceControlExtractor,
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
     * Validates existence and the parallel-safe marker. Constructor arity is
     * not checked; the marker promises construction without container injection.
     *
     * @param list<class-string<MetricCollectorInterface>> $classNames
     *
     * @return list<MetricCollectorInterface>
     */
    private static function instantiateCollectors(
        array $classNames,
        LcomCollectionConfiguration $lcomConfiguration,
    ): array {
        $collectors = [];

        foreach ($classNames as $className) {
            self::assertInstantiable($className);

            /** @var MetricCollectorInterface $collector */
            $collector = new $className();
            self::applyRuntimeConfiguration($collector, $lcomConfiguration);
            $collectors[] = $collector;
        }

        return $collectors;
    }

    /**
     * Instantiates derived collectors from class names.
     *
     * Validates existence and the parallel-safe marker. Constructor arity is
     * not checked; the marker promises construction without container injection.
     *
     * @param list<class-string<DerivedCollectorInterface>> $classNames
     *
     * @return list<DerivedCollectorInterface>
     */
    private static function instantiateDerivedCollectors(
        array $classNames,
        LcomCollectionConfiguration $lcomConfiguration,
    ): array {
        $collectors = [];

        foreach ($classNames as $className) {
            self::assertInstantiable($className);

            /** @var DerivedCollectorInterface $collector */
            $collector = new $className();
            self::applyRuntimeConfiguration($collector, $lcomConfiguration);
            $collectors[] = $collector;
        }

        return $collectors;
    }

    /**
     * Asserts that a collector class may be instantiated in a parallel worker.
     *
     * Only collectors implementing ParallelSafeCollectorInterface are allowed.
     * This provides a compile-time contract instead of runtime reflection.
     *
     * Both mismatches refuse. Dropping the collector instead made `--workers=N`
     * measure less than `--workers=0` with nothing said about it anywhere a
     * caller looks: the metrics were simply absent, the rules reading them
     * reported nothing, coverage stayed complete and the exit code stayed
     * normal. The warning it wrote went to the worker's own STDERR.
     *
     * @param class-string $className
     */
    public static function assertInstantiable(string $className): void
    {
        WorkerComposition::assertCollectorInstantiable($className);
    }

    private static function applyRuntimeConfiguration(
        object $collector,
        LcomCollectionConfiguration $configuration,
    ): void {
        if ($collector instanceof LcomCollectionConfigurableInterface) {
            $collector->applyLcomCollectionConfiguration($configuration);
        }
    }

    /** @return class-string<DependencyTraversalParticipantInterface> */
    public static function validateDependencyTraversalParticipantClass(string $className): string
    {
        return WorkerComposition::validateDependencyTraversalParticipantClass($className);
    }
}
