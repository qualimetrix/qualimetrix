<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Evaluation;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\ComputedMetricDependencyGraphCalculator;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Definition\ComputedMetricDefinition;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Definition\ComputedMetricDefinitionCatalogInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricBag;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricRepositoryInterface;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Profiler\Contract\ProfilerInterface;
use Qualimetrix\Core\Symbol\SymbolLevel;
use Qualimetrix\Core\Symbol\SymbolPath;
use RuntimeException;
use Throwable;

class ComputedMetricEvaluator
{
    private readonly ComputedMetricExpression $expression;
    private readonly ComputedMetricDependencyGraphCalculator $dependencyGraphCalculator;

    public function __construct(
        private readonly ComputedMetricDefinitionCatalogInterface $definitionCatalog,
        private readonly ProfilerInterface $profiler,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
        $this->expression = new ComputedMetricExpression();
        $this->dependencyGraphCalculator = new ComputedMetricDependencyGraphCalculator($this->expression);
    }

    public function evaluate(MetricRepositoryInterface $repo, int $filesAnalyzed): void
    {
        $definitions = $this->definitionCatalog->all();
        if ($filesAnalyzed === 0 || $definitions === []) {
            return;
        }

        $profiler = $this->profiler;
        $profiler->start('computed', 'pipeline');

        // Build dependency graph and topological sort
        $sorted = $this->topologicalSort($definitions);

        // Evaluate in dependency order
        foreach ($sorted as $definition) {
            $profiler->start('computed.' . $definition->name, 'computed');

            foreach ($definition->levels as $level) {
                $formula = $definition->getFormulaForLevel($level);
                if ($formula === null) {
                    continue;
                }

                $this->evaluateAtLevel($repo, $definition, $level, $formula);
            }

            $profiler->stop('computed.' . $definition->name);
        }

        $profiler->stop('computed');
    }

    private function evaluateAtLevel(
        MetricRepositoryInterface $repo,
        ComputedMetricDefinition $definition,
        SymbolLevel $level,
        string $formula,
    ): void {
        $symbols = $this->getSymbolsForLevel($repo, $level);

        $this->validateFormulaVariables($repo, $definition, $level, $formula, $symbols);

        foreach ($symbols as [$symbolPath, $file, $line]) {
            $metricBag = $repo->get($symbolPath);
            $variables = $this->buildVariableMap($metricBag);

            try {
                $result = $this->expression->evaluate($formula, $variables);
            } catch (Throwable $e) {
                $this->logger->warning('Computed metric evaluation failed', [
                    'metric' => $definition->name,
                    'symbol' => $symbolPath->toString(),
                    'level' => $level->value,
                    'error' => $e->getMessage(),
                ]);

                continue;
            }

            if (!is_numeric($result)) {
                $this->logger->warning('Computed metric returned non-numeric result', [
                    'metric' => $definition->name,
                    'symbol' => $symbolPath->toString(),
                ]);

                continue;
            }

            $result = (float) $result;

            if (is_nan($result) || is_infinite($result)) {
                $this->logger->warning('Computed metric returned NaN or Infinity', [
                    'metric' => $definition->name,
                    'symbol' => $symbolPath->toString(),
                ]);

                continue;
            }

            $repo->addScalar($symbolPath, $definition->name, $result);
        }
    }

    /**
     * Validates that all required formula variables exist in the metric repository.
     *
     * Keys guarded by null-coalescing (`??`) are intentionally optional and skipped.
     * References to other computed metrics (`health.*`, `computed.*`) are validated
     * separately by `ComputedMetricFormulaValidator` and also skipped here.
     *
     * @param list<array{SymbolPath, ?RelativePath, ?int}> $symbols
     *
     * @throws RuntimeException If the formula references metrics that do not exist at this level
     */
    private function validateFormulaVariables(
        MetricRepositoryInterface $repo,
        ComputedMetricDefinition $definition,
        SymbolLevel $level,
        string $formula,
        array $symbols,
    ): void {
        // Collect union of all known metric keys across all symbols at this level
        $allKnownKeys = $this->collectKnownMetricKeys($repo, $symbols);

        // Skip validation when no metrics exist at this level — there is no data to validate against.
        // In production, aggregation populates metrics before evaluation; in unit tests, data may be sparse.
        if ($allKnownKeys === []) {
            return;
        }

        // Extract required variables (excluding null-coalescing-protected ones)
        $requiredVars = $this->extractRequiredFormulaVariables($formula);
        $unknownVars = $this->findUnknownVariables($requiredVars, $allKnownKeys);

        if ($unknownVars !== []) {
            throw new RuntimeException(\sprintf(
                'Computed metric "%s" at level "%s" references unknown metrics: %s. Check the formula: %s',
                $definition->name,
                $level->value,
                implode(', ', $unknownVars),
                $formula,
            ));
        }
    }

    /**
     * Collects the union of all known metric keys across all symbols at a level.
     *
     * @param list<array{SymbolPath, ?RelativePath, ?int}> $symbols
     *
     * @return array<string, true>
     */
    private function collectKnownMetricKeys(MetricRepositoryInterface $repo, array $symbols): array
    {
        $allKnownKeys = [];
        foreach ($symbols as [$symbolPath]) {
            foreach (array_keys($repo->get($symbolPath)->all()) as $key) {
                $allKnownKeys[$key] = true;
            }
        }

        return $allKnownKeys;
    }

    /**
     * Finds formula variables that are neither known metrics nor computed-metric references.
     *
     * @param list<string> $requiredVars
     * @param array<string, true> $allKnownKeys
     *
     * @return list<string>
     */
    private function findUnknownVariables(array $requiredVars, array $allKnownKeys): array
    {
        $unknownVars = [];
        foreach ($requiredVars as $key) {
            // Skip computed metric references — validated by ComputedMetricFormulaValidator
            if (str_starts_with($key, 'health.') || str_starts_with($key, 'computed.')) {
                continue;
            }

            if (!isset($allKnownKeys[$key])) {
                $unknownVars[] = $key;
            }
        }

        return $unknownVars;
    }

    /**
     * Extracts formula variables that are NOT protected by null-coalescing (`??`).
     *
     * Variables appearing only in `(var ?? fallback)` patterns are intentionally optional
     * and should not trigger validation errors.
     *
     * @return list<string>
     */
    private function extractRequiredFormulaVariables(string $formula): array
    {
        return $this->expression->requiredKeysOf($formula);
    }

    /**
     * Sorts definitions in dependency order using Kahn's algorithm.
     *
     * @param list<ComputedMetricDefinition> $definitions
     *
     * @return list<ComputedMetricDefinition>
     */
    private function topologicalSort(array $definitions): array
    {
        $sorted = $this->dependencyGraphCalculator->sort($definitions);

        if ($sorted === null) {
            // Circular dependency — return original order and let config validation catch it
            $this->logger->warning('Circular dependency detected among computed metrics');

            return $definitions;
        }

        return $sorted;
    }

    /**
     * @return list<array{SymbolPath, ?RelativePath, ?int}>
     */
    private function getSymbolsForLevel(MetricRepositoryInterface $repo, SymbolLevel $level): array
    {
        return match ($level) {
            SymbolLevel::Project => [[SymbolPath::forProject(), null, null]],
            SymbolLevel::Namespace_ => array_map(
                static fn(string $ns) => [SymbolPath::forNamespace($ns), null, null],
                $repo->getNamespaces(),
            ),
            SymbolLevel::Class_ => array_map(
                static fn($info) => [$info->symbolPath, $info->file, $info->line],
                iterator_to_array($repo->all(SymbolLevel::Class_), false),
            ),
            SymbolLevel::Callable, SymbolLevel::File => [],
        };
    }

    /**
     * @return array{m: MetricLookup}
     */
    private function buildVariableMap(MetricBag $bag): array
    {
        return ['m' => new MetricLookup($bag->all())];
    }

}
