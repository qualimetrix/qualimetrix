<?php

declare(strict_types=1);

namespace AiMessDetector\Analysis\Aggregator;

use AiMessDetector\Analysis\Repository\InMemoryMetricRepository;
use AiMessDetector\Core\Metric\AggregationStrategy;
use AiMessDetector\Core\Metric\MetricBag;
use AiMessDetector\Core\Metric\MetricCollectorInterface;
use AiMessDetector\Core\Metric\MetricDefinition;
use AiMessDetector\Core\Metric\SymbolLevel;
use AiMessDetector\Core\Profiler\ProfilerHolder;
use AiMessDetector\Core\Symbol\SymbolInfo;
use AiMessDetector\Core\Symbol\SymbolType;
use AiMessDetector\Core\Violation\SymbolPath;
use Traversable;

/**
 * Aggregates metrics from lower levels (Method, File) to higher levels (Class, Namespace, Project).
 *
 * Uses MetricDefinitions from collectors to determine which aggregation strategies to apply.
 * No hardcoded metric names — fully generic.
 */
final class MetricAggregator
{
    /** @var list<MetricCollectorInterface> */
    private readonly array $collectors;

    /**
     * @param iterable<MetricCollectorInterface> $collectors
     */
    public function __construct(iterable $collectors)
    {
        $this->collectors = $collectors instanceof Traversable
            ? iterator_to_array($collectors, false)
            : array_values($collectors);
    }

    /**
     * Aggregates metrics and stores results in the repository.
     */
    public function aggregate(InMemoryMetricRepository $repository): void
    {
        $profiler = ProfilerHolder::get();

        $profiler->start('aggregation.collect_definitions', 'aggregation');
        $definitions = $this->collectDefinitions();
        $profiler->stop('aggregation.collect_definitions');

        if ($definitions === []) {
            return;
        }

        // Phase 1: Aggregate Method → Class
        $profiler->start('aggregation.methods_to_classes', 'aggregation');
        $this->aggregateMethodsToClasses($repository, $definitions);
        $profiler->stop('aggregation.methods_to_classes');

        // Phase 2: Aggregate File/Class → Namespace
        $profiler->start('aggregation.to_namespaces', 'aggregation');
        $this->aggregateToNamespaces($repository, $definitions);
        $profiler->stop('aggregation.to_namespaces');

        // Phase 3: Aggregate Namespace → Project
        $profiler->start('aggregation.to_project', 'aggregation');
        $this->aggregateToProject($repository, $definitions);
        $profiler->stop('aggregation.to_project');
    }

    /**
     * Collects all metric definitions from all collectors.
     *
     * @return list<MetricDefinition>
     */
    private function collectDefinitions(): array
    {
        $definitions = [];

        foreach ($this->collectors as $collector) {
            foreach ($collector->getMetricDefinitions() as $definition) {
                $definitions[] = $definition;
            }
        }

        return $definitions;
    }

    /**
     * Aggregates method-level metrics to class level.
     *
     * @param list<MetricDefinition> $definitions
     */
    private function aggregateMethodsToClasses(InMemoryMetricRepository $repository, array $definitions): void
    {
        $profiler = ProfilerHolder::get();

        // Filter definitions that aggregate at Method level to Class level
        $methodDefinitions = array_filter(
            $definitions,
            static fn(MetricDefinition $d): bool => $d->collectedAt === SymbolLevel::Method
                && $d->hasAggregationsForLevel(SymbolLevel::Class_),
        );

        if ($methodDefinitions === []) {
            return;
        }

        $methodDefinitions = array_values($methodDefinitions);

        // Group methods by class
        $profiler->start('aggregation.methods_to_classes.group', 'aggregation');
        $methodsByClass = $this->groupMethodsByClass($repository);
        $profiler->stop('aggregation.methods_to_classes.group');

        $profiler->start('aggregation.methods_to_classes.process', 'aggregation');
        foreach ($methodsByClass as $methodInfos) {
            if ($methodInfos === []) {
                continue;
            }

            $firstInfo = $methodInfos[0];
            $classPath = SymbolPath::forClass(
                $firstInfo->symbolPath->namespace ?? '',
                $firstInfo->symbolPath->type ?? '',
            );

            // Collect metric values from all methods in this class
            $metricValues = $this->collectMetricValues($repository, $methodInfos, $methodDefinitions);

            // Apply aggregation strategies and create MetricBag for class
            $classBag = $this->applyAggregations($metricValues, $methodDefinitions, SymbolLevel::Class_);

            // Add WMC alias (WMC = ccn.sum)
            $ccnSum = $classBag->get('ccn.sum');
            if ($ccnSum !== null) {
                $classBag = $classBag->with('wmc', $ccnSum);
            }

            // Add method symbol count for class-level rules (distinct from methodCount quality metric)
            $classBag = $classBag->with('symbolMethodCount', \count($methodInfos));

            // Store aggregated metrics for class
            $repository->add($classPath, $classBag, $firstInfo->file, 0);
        }
        $profiler->stop('aggregation.methods_to_classes.process');
    }

    /**
     * Aggregates file/class-level metrics to namespace level.
     *
     * @param list<MetricDefinition> $definitions
     */
    private function aggregateToNamespaces(InMemoryMetricRepository $repository, array $definitions): void
    {
        $profiler = ProfilerHolder::get();

        // Filter definitions that aggregate to Namespace level
        $namespaceDefinitions = array_filter(
            $definitions,
            static fn(MetricDefinition $d): bool => $d->hasAggregationsForLevel(SymbolLevel::Namespace_),
        );

        if ($namespaceDefinitions === []) {
            return;
        }

        $namespaceDefinitions = array_values($namespaceDefinitions);

        // Build maps: file → namespace and namespace → fileSymbols
        $profiler->start('aggregation.to_namespaces.build_map', 'aggregation');
        $fileToNamespace = $this->buildFileToNamespaceMap($repository);
        $namespaceToFileSymbols = $this->buildNamespaceToFileSymbolsMap($repository, $fileToNamespace);
        $profiler->stop('aggregation.to_namespaces.build_map');

        $profiler->start('aggregation.to_namespaces.process', 'aggregation');
        foreach ($repository->getNamespaces() as $namespace) {
            $symbolInfos = $repository->forNamespace($namespace);

            if ($symbolInfos === []) {
                continue;
            }

            // File symbols for this namespace (O(1) lookup from pre-built map)
            $fileSymbols = $namespaceToFileSymbols[$namespace] ?? [];

            // Collect values from appropriate source levels
            $metricValues = $this->collectNamespaceMetricValues(
                $repository,
                $symbolInfos,
                $fileSymbols,
                $namespaceDefinitions,
            );

            // Apply aggregation strategies
            $namespaceBag = $this->applyAggregations($metricValues, $namespaceDefinitions, SymbolLevel::Namespace_);

            // Add method/class counts
            $namespaceBag = $this->addSymbolCounts($namespaceBag, $symbolInfos);

            // Store aggregated metrics for namespace
            $firstFile = $symbolInfos[0]->file;
            $namespacePath = SymbolPath::forNamespace($namespace);
            $repository->add($namespacePath, $namespaceBag, $firstFile, 0);
        }
        $profiler->stop('aggregation.to_namespaces.process');
    }

    /**
     * Aggregates namespace-level metrics to project level.
     *
     * @param list<MetricDefinition> $definitions
     */
    private function aggregateToProject(InMemoryMetricRepository $repository, array $definitions): void
    {
        $profiler = ProfilerHolder::get();

        // Filter definitions that aggregate to Project level
        $projectDefinitions = array_filter(
            $definitions,
            static fn(MetricDefinition $d): bool => $d->hasAggregationsForLevel(SymbolLevel::Project),
        );

        if ($projectDefinitions === []) {
            return;
        }

        $projectDefinitions = array_values($projectDefinitions);

        // Collect values from all source-level symbols across all namespaces
        $profiler->start('aggregation.to_project.collect_symbols', 'aggregation');
        $allSymbolInfos = [];

        foreach ($repository->getNamespaces() as $namespace) {
            foreach ($repository->forNamespace($namespace) as $info) {
                $allSymbolInfos[] = $info;
            }
        }

        if ($allSymbolInfos === []) {
            $profiler->stop('aggregation.to_project.collect_symbols');
            return;
        }

        // Get all file symbols for project-level aggregation
        $allFileSymbols = array_values(iterator_to_array($repository->all(SymbolType::File)));
        $profiler->stop('aggregation.to_project.collect_symbols');

        $profiler->start('aggregation.to_project.process', 'aggregation');
        $metricValues = $this->collectNamespaceMetricValues(
            $repository,
            $allSymbolInfos,
            $allFileSymbols,
            $projectDefinitions,
        );

        // Apply aggregation strategies
        $projectBag = $this->applyAggregations($metricValues, $projectDefinitions, SymbolLevel::Project);

        // Add counts
        $projectBag = $this->addSymbolCounts($projectBag, $allSymbolInfos);

        // Store aggregated metrics for project (empty namespace = project level)
        $firstFile = $allSymbolInfos[0]->file;
        $projectPath = SymbolPath::forNamespace('');
        $repository->add($projectPath, $projectBag, $firstFile, 0);
        $profiler->stop('aggregation.to_project.process');
    }

    /**
     * Groups method symbols by their parent class.
     *
     * @return array<string, list<SymbolInfo>>
     */
    private function groupMethodsByClass(InMemoryMetricRepository $repository): array
    {
        $methodsByClass = [];

        foreach ($repository->all(SymbolType::Method) as $methodInfo) {
            $path = $methodInfo->symbolPath;

            // Skip functions (no class)
            if ($path->type === null) {
                continue;
            }

            $classCanonical = SymbolPath::forClass(
                $path->namespace ?? '',
                $path->type,
            )->toCanonical();

            $methodsByClass[$classCanonical][] = $methodInfo;
        }

        return $methodsByClass;
    }

    /**
     * Collects raw metric values from symbols.
     *
     * @param list<SymbolInfo> $symbolInfos
     * @param list<MetricDefinition> $definitions
     *
     * @return array<string, list<int|float>> metric name => values
     */
    private function collectMetricValues(
        InMemoryMetricRepository $repository,
        array $symbolInfos,
        array $definitions,
    ): array {
        $values = [];

        foreach ($definitions as $definition) {
            $values[$definition->name] = [];
        }

        foreach ($symbolInfos as $info) {
            $bag = $repository->get($info->symbolPath);

            foreach ($definitions as $definition) {
                $value = $bag->get($definition->name);

                if ($value !== null) {
                    $values[$definition->name][] = $value;
                }
            }
        }

        return $values;
    }

    /**
     * Builds a map of file path → namespace based on class/method symbols.
     *
     * @return array<string, string> file path => namespace
     */
    private function buildFileToNamespaceMap(InMemoryMetricRepository $repository): array
    {
        $map = [];

        foreach ($repository->all(SymbolType::Class_) as $classInfo) {
            $namespace = $classInfo->symbolPath->namespace;

            if ($namespace !== null) {
                $map[$classInfo->file] = $namespace;
            }
        }

        // Also check methods (for files where class wasn't explicitly registered)
        foreach ($repository->all(SymbolType::Method) as $methodInfo) {
            $namespace = $methodInfo->symbolPath->namespace;

            if ($namespace !== null && !isset($map[$methodInfo->file])) {
                $map[$methodInfo->file] = $namespace;
            }
        }

        return $map;
    }

    /**
     * Builds a map of namespace → list of File symbols.
     * This allows O(1) lookup instead of O(Nf) for each namespace.
     *
     * @param array<string, string> $fileToNamespace
     *
     * @return array<string, list<SymbolInfo>>
     */
    private function buildNamespaceToFileSymbolsMap(
        InMemoryMetricRepository $repository,
        array $fileToNamespace,
    ): array {
        $map = [];

        foreach ($repository->all(SymbolType::File) as $fileInfo) {
            $filePath = $fileInfo->file;

            if (isset($fileToNamespace[$filePath])) {
                $namespace = $fileToNamespace[$filePath];
                $map[$namespace][] = $fileInfo;
            }
        }

        return $map;
    }

    /**
     * Collects metric values for namespace/project aggregation.
     *
     * For Method-collected metrics, reads from Class level (ccn.sum, ccn.avg, etc.)
     * For Class-collected metrics, reads directly from Class level.
     * For File-collected metrics, reads directly from File level.
     *
     * @param list<SymbolInfo> $symbolInfos Class/Method symbols
     * @param list<SymbolInfo> $fileSymbols File symbols for this namespace
     * @param list<MetricDefinition> $definitions
     *
     * @return array<string, list<int|float>> metric name => values
     */
    private function collectNamespaceMetricValues(
        InMemoryMetricRepository $repository,
        array $symbolInfos,
        array $fileSymbols,
        array $definitions,
    ): array {
        $values = [];

        foreach ($definitions as $definition) {
            $values[$definition->name] = [];
        }

        // Collect from class/method symbols
        foreach ($symbolInfos as $info) {
            $path = $info->symbolPath;
            $bag = $repository->get($path);

            foreach ($definitions as $definition) {
                // Must be a class symbol (not method) for class/method-level metrics
                if ($path->type === null || $path->member !== null) {
                    continue;
                }

                // For Method-collected metrics, read aggregated sum from class
                if ($definition->collectedAt === SymbolLevel::Method) {
                    $sumValue = $bag->get($definition->aggregatedName(AggregationStrategy::Sum));

                    if ($sumValue !== null) {
                        $values[$definition->name][] = $sumValue;
                    }
                }

                // For Class-collected metrics, read directly from class
                if ($definition->collectedAt === SymbolLevel::Class_) {
                    $value = $bag->get($definition->name);

                    if ($value !== null) {
                        $values[$definition->name][] = $value;
                    }
                }
            }
        }

        // Collect from file symbols
        foreach ($fileSymbols as $fileInfo) {
            $bag = $repository->get($fileInfo->symbolPath);

            foreach ($definitions as $definition) {
                // Only read from File symbols for File-collected metrics
                if ($definition->collectedAt !== SymbolLevel::File) {
                    continue;
                }

                $value = $bag->get($definition->name);

                if ($value !== null) {
                    $values[$definition->name][] = $value;
                }
            }
        }

        return $values;
    }

    /**
     * Applies aggregation strategies to collected values.
     *
     * @param array<string, list<int|float>> $metricValues
     * @param list<MetricDefinition> $definitions
     */
    private function applyAggregations(
        array $metricValues,
        array $definitions,
        SymbolLevel $targetLevel,
    ): MetricBag {
        $bag = new MetricBag();

        foreach ($definitions as $definition) {
            $values = $metricValues[$definition->name] ?? [];

            if ($values === []) {
                continue;
            }

            foreach ($definition->getStrategiesForLevel($targetLevel) as $strategy) {
                $aggregatedValue = $this->applyStrategy($strategy, $values);
                $aggregatedName = $definition->aggregatedName($strategy);
                $bag = $bag->with($aggregatedName, $aggregatedValue);
            }
        }

        return $bag;
    }

    /**
     * Applies a single aggregation strategy to a list of values.
     *
     * @param list<int|float> $values
     */
    private function applyStrategy(AggregationStrategy $strategy, array $values): int|float
    {
        if ($values === []) {
            return 0;
        }

        return match ($strategy) {
            AggregationStrategy::Sum => array_sum($values),
            AggregationStrategy::Average => array_sum($values) / \count($values),
            AggregationStrategy::Max => max($values),
            AggregationStrategy::Min => min($values),
            AggregationStrategy::Count => \count($values),
        };
    }

    /**
     * Adds method and class counts to the metric bag.
     *
     * @param list<SymbolInfo> $symbolInfos
     */
    private function addSymbolCounts(MetricBag $bag, array $symbolInfos): MetricBag
    {
        $methodCount = 0;
        $classCount = 0;

        foreach ($symbolInfos as $info) {
            $path = $info->symbolPath;

            if ($path->type !== null && $path->member !== null) {
                $methodCount++;
            } elseif ($path->type !== null && $path->member === null) {
                $classCount++;
            }
        }

        return $bag
            ->with('symbolMethodCount', $methodCount)
            ->with('symbolClassCount', $classCount);
    }
}
