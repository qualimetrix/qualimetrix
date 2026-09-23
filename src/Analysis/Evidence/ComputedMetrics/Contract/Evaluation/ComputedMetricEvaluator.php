<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Evaluation;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\ComputedMetricDependencyGraphCalculator;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\ComputedMetricFormulaValidator;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Definition\ComputedMetricDefinition;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Definition\ComputedMetricDefinitionCatalogInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricBag;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricRepositoryInterface;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Profiler\Contract\ProfilerInterface;
use Qualimetrix\Core\Symbol\SymbolLevel;
use Qualimetrix\Core\Symbol\SymbolPath;
use Throwable;

class ComputedMetricEvaluator
{
    private const int SKIPPED_SYMBOL_SAMPLE = 5;

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

        $skipped = [];
        $missingKeys = [];

        foreach ($symbols as [$symbolPath, $file, $line]) {
            $metricBag = $repo->get($symbolPath);
            // `get()` answers null exactly where `MetricLookup` would hand the
            // formula null: a bag holds only `int|float`.
            $missing = $this->expression->missingKeysOf(
                $formula,
                static fn(string $key): bool => $metricBag->get($key) !== null,
            );

            if ($missing !== []) {
                // The level carries the key somewhere; this symbol does not.
                // Evaluating would hand `null` to the arithmetic, which PHP
                // coerces to 0 — a fabricated measurement that scores the
                // symbol and can raise a finding. The honest answer is no value.
                $skipped[] = $symbolPath->toString();
                $missingKeys = [...$missingKeys, ...$missing];

                continue;
            }

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

        $this->reportSkipped($definition, $level, $skipped, $missingKeys);
    }

    /**
     * One line per metric and level: a formula that misses on most of a large
     * project would otherwise print one line per symbol.
     *
     * @param list<string> $skipped
     * @param list<string> $missingKeys
     */
    private function reportSkipped(
        ComputedMetricDefinition $definition,
        SymbolLevel $level,
        array $skipped,
        array $missingKeys,
    ): void {
        if ($skipped === []) {
            return;
        }

        $this->logger->warning('Computed metric published no value for symbols lacking a metric its formula reads without a "??" fallback', [
            'metric' => $definition->name,
            'level' => $level->value,
            'skipped' => \count($skipped),
            'symbols' => self::sampleOf($skipped),
            'missing' => implode(', ', array_values(array_unique($missingKeys))),
        ]);
    }

    /** @param non-empty-list<string> $symbols */
    private static function sampleOf(array $symbols): string
    {
        $sample = implode(', ', \array_slice($symbols, 0, self::SKIPPED_SYMBOL_SAMPLE));
        $rest = \count($symbols) - self::SKIPPED_SYMBOL_SAMPLE;

        return $rest > 0 ? \sprintf('%s (and %d more)', $sample, $rest) : $sample;
    }

    /**
     * Refuses a formula that would read, unguarded, a metric no symbol at this
     * level carries.
     *
     * Presence is the union over the level's symbols, so a key some symbol
     * carries is left to the per-symbol skip. A read behind `??` counts only
     * where the fallback is reached. A reference to another computed metric is
     * judged by the same union: evaluation runs in dependency order, so it is
     * already published wherever it will be. Configuration has refused a bare
     * one read at a level it does not declare; what remains is a chain such as
     * `m["computed.x"] ?? m["size.y"]`, whose measured link only a run can
     * judge.
     *
     * @param list<array{SymbolPath, ?RelativePath, ?int}> $symbols
     *
     * @throws \Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal if the formula names a metric no symbol at this level carries
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

        $unknownVars = $this->expression->missingKeysOf(
            $formula,
            static fn(string $key): bool => isset($allKnownKeys[$key]),
        );

        if ($unknownVars !== []) {
            // The same class of user mistake as a misspelled key, and refused
            // by the same class. A `RuntimeException` here surfaced as
            // "Internal error" with exit code 1 — the code that means
            // "warnings were found", so CI read a refusal as an ordinary result.
            ComputedMetricFormulaValidator::refuseMetricsAbsentAtLevel(
                $definition->name,
                $unknownVars,
                $level->value,
                $formula,
            );
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
