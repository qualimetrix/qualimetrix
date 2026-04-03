<?php

declare(strict_types=1);

namespace Qualimetrix\Metrics\ComputedMetric;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Qualimetrix\Core\ComputedMetric\ComputedMetricDefinition;
use Qualimetrix\Core\Metric\MetricBag;
use Qualimetrix\Core\Metric\MetricRepositoryInterface;
use Qualimetrix\Core\Profiler\ProfilerHolder;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Core\Symbol\SymbolType;
use RuntimeException;
use Symfony\Component\ExpressionLanguage\ExpressionLanguage;
use Throwable;

final class ComputedMetricEvaluator
{
    private readonly ExpressionLanguage $expressionLanguage;

    public function __construct(
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
        $this->expressionLanguage = new ExpressionLanguage();
        $this->registerMathFunctions();
    }

    /**
     * Computes all definitions and stores results in repository.
     *
     * @param list<ComputedMetricDefinition> $definitions
     */
    public function compute(MetricRepositoryInterface $repo, array $definitions): void
    {
        if ($definitions === []) {
            return;
        }

        $profiler = ProfilerHolder::get();

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
    }

    private function evaluateAtLevel(
        MetricRepositoryInterface $repo,
        ComputedMetricDefinition $definition,
        SymbolType $level,
        string $formula,
    ): void {
        $symbols = $this->getSymbolsForLevel($repo, $level);

        $this->validateFormulaVariables($repo, $definition, $level, $formula, $symbols);

        foreach ($symbols as [$symbolPath, $file, $line]) {
            $metricBag = $repo->get($symbolPath);
            $variables = $this->buildVariableMap($metricBag);

            try {
                $result = $this->expressionLanguage->evaluate($formula, $variables);
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
     * Variables protected by null-coalescing (`??`) are intentionally optional and skipped.
     * References to other computed metrics (`health__*`, `computed__*`) are validated
     * separately by `ComputedMetricFormulaValidator` and also skipped here.
     *
     * @param list<array{SymbolPath, string, ?int}> $symbols
     *
     * @throws RuntimeException If the formula references metrics that do not exist at this level
     */
    private function validateFormulaVariables(
        MetricRepositoryInterface $repo,
        ComputedMetricDefinition $definition,
        SymbolType $level,
        string $formula,
        array $symbols,
    ): void {
        // Collect union of all known metric keys across all symbols at this level
        $allKnownKeys = [];
        foreach ($symbols as [$symbolPath]) {
            foreach ($repo->get($symbolPath)->all() as $key => $value) {
                $allKnownKeys[str_replace('.', '__', $key)] = true;
            }
        }

        // Skip validation when no metrics exist at this level — there is no data to validate against.
        // In production, aggregation populates metrics before evaluation; in unit tests, data may be sparse.
        if ($allKnownKeys === []) {
            return;
        }

        // Extract required variables (excluding null-coalescing-protected ones)
        $requiredVars = $this->extractRequiredFormulaVariables($formula);

        $unknownVars = [];
        foreach ($requiredVars as $var) {
            // Skip computed metric references — validated by ComputedMetricFormulaValidator
            if (str_starts_with($var, 'health__') || str_starts_with($var, 'computed__')) {
                continue;
            }

            if (!isset($allKnownKeys[$var])) {
                $unknownVars[] = str_replace('__', '.', $var);
            }
        }

        if ($unknownVars !== []) {
            $levelKey = match ($level) {
                SymbolType::Class_ => 'class',
                SymbolType::Namespace_ => 'namespace',
                SymbolType::Project => 'project',
                default => $level->value,
            };

            throw new RuntimeException(\sprintf(
                'Computed metric "%s" at level "%s" references unknown metrics: %s. Check the formula: %s',
                $definition->name,
                $levelKey,
                implode(', ', $unknownVars),
                $formula,
            ));
        }
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
        $allVars = $this->extractFormulaVariables($formula);
        $optionalVars = $this->extractNullCoalescingVariables($formula);

        $required = [];
        foreach ($allVars as $var) {
            if (!isset($optionalVars[$var])) {
                $required[] = $var;
            }
        }

        return $required;
    }

    /**
     * Extracts all variable-like tokens from a formula, excluding known functions and EL keywords.
     *
     * @return list<string>
     */
    private function extractFormulaVariables(string $formula): array
    {
        if (preg_match_all('/\b([a-zA-Z_][a-zA-Z0-9_]*)\b/', $formula, $matches) === false) {
            return [];
        }

        $excluded = array_flip([...self::KNOWN_FUNCTIONS, ...self::EL_KEYWORDS]);

        $variables = [];
        $seen = [];
        foreach ($matches[1] as $token) {
            if (isset($excluded[$token]) || isset($seen[$token])) {
                continue;
            }
            $variables[] = $token;
            $seen[$token] = true;
        }

        return $variables;
    }

    /**
     * Extracts variables that appear on the left side of `??` (null-coalescing).
     *
     * Matches patterns like `(var ?? fallback)` and `var ?? fallback`.
     *
     * @return array<string, true>
     */
    private function extractNullCoalescingVariables(string $formula): array
    {
        $optional = [];

        // Match: identifier followed by optional whitespace and ??
        if (preg_match_all('/\b([a-zA-Z_][a-zA-Z0-9_]*)\s*\?\?/', $formula, $matches)) {
            foreach ($matches[1] as $var) {
                $optional[$var] = true;
            }
        }

        return $optional;
    }

    /** @var list<string> */
    private const array KNOWN_FUNCTIONS = [
        'min', 'max', 'abs', 'sqrt', 'log', 'log10', 'clamp',
    ];

    /** @var list<string> */
    private const array EL_KEYWORDS = [
        'true', 'false', 'null', 'not', 'and', 'or', 'in', 'matches',
    ];

    /**
     * @return list<array{SymbolPath, string, ?int}>
     */
    private function getSymbolsForLevel(MetricRepositoryInterface $repo, SymbolType $level): array
    {
        return match ($level) {
            SymbolType::Project => [[SymbolPath::forProject(), '', null]],
            SymbolType::Namespace_ => array_map(
                static fn(string $ns) => [SymbolPath::forNamespace($ns), '', null],
                $repo->getNamespaces(),
            ),
            SymbolType::Class_ => array_map(
                static fn($info) => [$info->symbolPath, $info->file, $info->line],
                iterator_to_array($repo->all(SymbolType::Class_), false),
            ),
            default => [],
        };
    }

    /**
     * @return array<string, int|float|null>
     */
    private function buildVariableMap(MetricBag $bag): array
    {
        $variables = [];
        foreach ($bag->all() as $key => $value) {
            // Replace . with __ for ExpressionLanguage compatibility
            $elKey = str_replace('.', '__', $key);
            $variables[$elKey] = $value;
        }

        return $variables;
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
        $byName = [];
        foreach ($definitions as $def) {
            $byName[$def->name] = $def;
        }

        // Build adjacency: deps[A] = [B, C] means A depends on B and C
        $deps = [];
        foreach ($definitions as $def) {
            $deps[$def->name] = [];
            foreach ($def->formulas as $formula) {
                foreach ($this->extractComputedMetricDeps($formula) as $depName) {
                    if (isset($byName[$depName]) && $depName !== $def->name) {
                        $deps[$def->name][] = $depName;
                    }
                }
            }
            $deps[$def->name] = array_unique($deps[$def->name]);
        }

        // Reverse edges: reverseDeps[B] = [A] means "A depends on B, so after B is done, A can proceed"
        $reverseDeps = array_fill_keys(array_keys($byName), []);
        $inDegree = array_fill_keys(array_keys($byName), 0);

        foreach ($deps as $node => $nodeDeps) {
            $inDegree[$node] = \count($nodeDeps);
            foreach ($nodeDeps as $dep) {
                if (isset($reverseDeps[$dep])) {
                    $reverseDeps[$dep][] = $node;
                }
            }
        }

        $queue = [];
        foreach ($inDegree as $node => $degree) {
            if ($degree === 0) {
                $queue[] = $node;
            }
        }

        $sorted = [];
        while ($queue !== []) {
            $node = array_shift($queue);
            $sorted[] = $byName[$node];
            foreach ($reverseDeps[$node] as $dependent) {
                $inDegree[$dependent]--;
                if ($inDegree[$dependent] === 0) {
                    $queue[] = $dependent;
                }
            }
        }

        if (\count($sorted) !== \count($definitions)) {
            // Circular dependency — return original order and let config validation catch it
            $this->logger->warning('Circular dependency detected among computed metrics');

            return $definitions;
        }

        return $sorted;
    }

    /**
     * Extract computed metric dependencies from a formula.
     * Variables matching health__* or computed__* are inter-metric references.
     *
     * @return list<string>
     */
    private function extractComputedMetricDeps(string $formula): array
    {
        $deps = [];
        if (preg_match_all('/\b(health__[a-zA-Z0-9_]+|computed__[a-zA-Z0-9_]+)\b/', $formula, $matches)) {
            foreach ($matches[1] as $var) {
                // Convert back: health__complexity → health.complexity
                $name = str_replace('__', '.', $var);
                $deps[] = $name;
            }
        }

        return array_values(array_unique($deps));
    }

    private function registerMathFunctions(): void
    {
        $phpFunctions = ['min', 'max', 'abs', 'sqrt', 'log', 'log10'];
        foreach ($phpFunctions as $fn) {
            $this->expressionLanguage->register(
                $fn,
                static fn(mixed ...$args) => \sprintf('%s(%s)', $fn, implode(', ', $args)),
                static fn(array $values, mixed ...$args) => $fn(...$args),
            );
        }

        // clamp(value, min, max)
        $this->expressionLanguage->register(
            'clamp',
            static fn(mixed $value, mixed $min, mixed $max) => \sprintf('max(%s, min(%s, %s))', $min, $max, $value),
            static fn(array $values, mixed $value, mixed $min, mixed $max) => max($min, min($max, $value)),
        );
    }
}
