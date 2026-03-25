<?php

declare(strict_types=1);

namespace AiMessDetector\Configuration;

use AiMessDetector\Core\ComputedMetric\ComputedMetricDefaults;
use AiMessDetector\Core\ComputedMetric\ComputedMetricDefinition;
use AiMessDetector\Core\Symbol\SymbolType;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\ExpressionLanguage\ExpressionFunction;
use Symfony\Component\ExpressionLanguage\ExpressionLanguage;
use Symfony\Component\ExpressionLanguage\SyntaxError;

/**
 * Merges default computed metric definitions with user overrides from YAML
 * and validates the result (syntax, coverage, circular deps, references).
 */
final class ComputedMetricsConfigResolver
{
    private readonly ExpressionLanguage $expressionLanguage;

    /** @var list<string> */
    private const array KNOWN_FUNCTIONS = [
        'min', 'max', 'abs', 'sqrt', 'log', 'log10', 'clamp',
    ];

    /** @var list<string> */
    private const array EL_KEYWORDS = [
        'true', 'false', 'null', 'not', 'and', 'or', 'in', 'matches',
    ];

    public function __construct()
    {
        $this->expressionLanguage = new ExpressionLanguage();
        $this->registerMathFunctions();
    }

    /**
     * @param array<string, mixed> $rawConfig The 'computed_metrics' section from YAML
     *
     * @return list<ComputedMetricDefinition>
     */
    public function resolve(array $rawConfig): array
    {
        // 1. Start with defaults
        $definitions = ComputedMetricDefaults::getDefaults();

        // 2. Apply user overrides
        foreach ($rawConfig as $name => $overrides) {
            if (!\is_array($overrides)) {
                continue;
            }

            // Handle enabled: false
            if (isset($overrides['enabled']) && $overrides['enabled'] === false) {
                unset($definitions[$name]);

                continue;
            }

            if (isset($definitions[$name])) {
                // Merge override into existing (health.*)
                $definitions[$name] = $this->mergeDefinition($definitions[$name], $overrides);
            } elseif (str_starts_with($name, 'health.')) {
                throw new RuntimeException(\sprintf(
                    'Computed metric name "%s" uses reserved "health.*" prefix. '
                    . 'Use "computed.*" prefix for user-defined metrics.',
                    $name,
                ));
            } else {
                // New user-defined metric
                $definitions[$name] = $this->createDefinition($name, $overrides);
            }
        }

        $result = array_values($definitions);

        // 3. Validate
        $this->validateFormulaSyntax($result);
        $this->validateFormulaCoverage($result);
        $this->validateCircularDependencies($result);
        $this->validateComputedMetricReferences($result);

        return $result;
    }

    /**
     * Merges user overrides into an existing definition.
     *
     * @param array<string, mixed> $overrides
     */
    private function mergeDefinition(ComputedMetricDefinition $base, array $overrides): ComputedMetricDefinition
    {
        $formulas = $base->formulas;

        // 'formula' (singular) is shorthand — overrides ALL levels with one formula.
        // This replaces any existing per-level formulas (including specialized ones
        // like health.coupling's project formula). If the user wants to override
        // only specific levels, they should use 'formulas' (plural) instead.
        if (isset($overrides['formula']) && \is_string($overrides['formula'])) {
            $shorthand = $overrides['formula'];
            foreach (['class', 'namespace', 'project'] as $levelKey) {
                $formulas[$levelKey] = $shorthand;
            }
        }

        // 'formulas' (plural) overrides per-level
        if (isset($overrides['formulas']) && \is_array($overrides['formulas'])) {
            foreach ($overrides['formulas'] as $levelKey => $formula) {
                if (\is_string($formula)) {
                    $formulas[$levelKey] = $formula;
                }
            }
        }

        $levels = $base->levels;
        if (isset($overrides['levels']) && \is_array($overrides['levels'])) {
            $levels = array_values(array_map($this->mapLevel(...), $overrides['levels']));
        }

        $thresholds = $this->resolveThresholdOverrides($overrides, $base->warningThreshold, $base->errorThreshold);

        return new ComputedMetricDefinition(
            name: $base->name,
            formulas: $formulas,
            description: isset($overrides['description']) && \is_string($overrides['description'])
                ? $overrides['description']
                : $base->description,
            levels: $levels,
            inverted: isset($overrides['inverted']) && \is_bool($overrides['inverted'])
                ? $overrides['inverted']
                : $base->inverted,
            warningThreshold: $thresholds['warningThreshold'],
            errorThreshold: $thresholds['errorThreshold'],
        );
    }

    /**
     * Creates a new user-defined computed metric definition.
     *
     * @param array<string, mixed> $config
     */
    private function createDefinition(string $name, array $config): ComputedMetricDefinition
    {
        $formulas = [];

        // 'formula' (singular) shorthand
        if (isset($config['formula']) && \is_string($config['formula'])) {
            $shorthand = $config['formula'];
            $formulas = ['class' => $shorthand, 'namespace' => $shorthand, 'project' => $shorthand];
        }

        // 'formulas' (plural) per-level — takes precedence
        if (isset($config['formulas']) && \is_array($config['formulas'])) {
            foreach ($config['formulas'] as $levelKey => $formula) {
                if (\is_string($formula)) {
                    $formulas[$levelKey] = $formula;
                }
            }
        }

        $levels = [SymbolType::Namespace_, SymbolType::Project];
        if (isset($config['levels']) && \is_array($config['levels'])) {
            $levels = array_values(array_map($this->mapLevel(...), $config['levels']));
        }

        $thresholds = $this->resolveThresholdOverrides($config, null, null);

        return new ComputedMetricDefinition(
            name: $name,
            formulas: $formulas,
            description: isset($config['description']) && \is_string($config['description'])
                ? $config['description']
                : '',
            levels: $levels,
            inverted: isset($config['inverted']) && $config['inverted'] === true,
            warningThreshold: $thresholds['warningThreshold'],
            errorThreshold: $thresholds['errorThreshold'],
        );
    }

    /**
     * Validates that all formula strings are syntactically valid ExpressionLanguage expressions.
     *
     * @param list<ComputedMetricDefinition> $definitions
     */
    private function validateFormulaSyntax(array $definitions): void
    {
        foreach ($definitions as $definition) {
            foreach ($definition->levels as $level) {
                $formula = $definition->getFormulaForLevel($level);
                if ($formula === null) {
                    continue; // Coverage validation handles missing formulas
                }

                $variables = $this->extractFormulaVariables($formula);

                try {
                    $this->expressionLanguage->parse($formula, $variables);
                } catch (SyntaxError $e) {
                    $levelKey = $this->levelToString($level);

                    throw new RuntimeException(\sprintf(
                        'Invalid formula syntax for computed metric "%s" at level "%s": %s (formula: %s)',
                        $definition->name,
                        $levelKey,
                        $e->getMessage(),
                        $formula,
                    ));
                }
            }
        }
    }

    /**
     * Validates that each level in a definition has a resolvable formula.
     *
     * @param list<ComputedMetricDefinition> $definitions
     */
    private function validateFormulaCoverage(array $definitions): void
    {
        foreach ($definitions as $definition) {
            foreach ($definition->levels as $level) {
                $formula = $definition->getFormulaForLevel($level);
                if ($formula === null) {
                    $levelKey = $this->levelToString($level);

                    throw new RuntimeException(\sprintf(
                        'Computed metric "%s" has no formula for level "%s"',
                        $definition->name,
                        $levelKey,
                    ));
                }
            }
        }
    }

    /**
     * Validates that there are no circular dependencies between computed metrics.
     *
     * @param list<ComputedMetricDefinition> $definitions
     */
    private function validateCircularDependencies(array $definitions): void
    {
        // Build name → dependencies map
        $graph = [];
        $nameSet = [];
        foreach ($definitions as $definition) {
            $nameSet[$definition->name] = true;
            $deps = [];
            foreach ($definition->formulas as $formula) {
                foreach ($this->extractComputedMetricReferences($formula) as $ref) {
                    $deps[$ref] = true;
                }
            }
            $graph[$definition->name] = array_keys($deps);
        }

        // Topological sort via DFS with cycle detection
        $visited = [];
        $inStack = [];

        $visit = function (string $node, array $path) use (&$visit, &$visited, &$inStack, $graph): void {
            if (isset($inStack[$node])) {
                $cycleStart = array_search($node, $path, true);
                \assert($cycleStart !== false);
                $cycle = \array_slice($path, (int) $cycleStart);
                $cycle[] = $node;

                throw new RuntimeException(\sprintf(
                    'Circular dependency detected in computed metrics: %s',
                    implode(' -> ', $cycle),
                ));
            }

            if (isset($visited[$node])) {
                return;
            }

            $inStack[$node] = true;
            $path[] = $node;

            foreach ($graph[$node] ?? [] as $dep) {
                // Only follow edges to known computed metrics
                if (isset($graph[$dep])) {
                    $visit($dep, $path);
                }
            }

            unset($inStack[$node]);
            $visited[$node] = true;
        };

        foreach (array_keys($graph) as $node) {
            $visit($node, []);
        }
    }

    /**
     * Validates that all formula references to health__* or computed__* correspond to existing definitions.
     *
     * @param list<ComputedMetricDefinition> $definitions
     */
    private function validateComputedMetricReferences(array $definitions): void
    {
        $nameSet = [];
        foreach ($definitions as $definition) {
            $nameSet[$definition->name] = true;
        }

        foreach ($definitions as $definition) {
            foreach ($definition->formulas as $formula) {
                foreach ($this->extractComputedMetricReferences($formula) as $ref) {
                    if (!isset($nameSet[$ref])) {
                        throw new RuntimeException(\sprintf(
                            'Computed metric "%s" references unknown metric "%s" in formula: %s',
                            $definition->name,
                            $ref,
                            $formula,
                        ));
                    }
                }
            }
        }
    }

    /**
     * Extracts variable-like tokens from a formula, excluding known functions and EL keywords.
     *
     * @return list<string>
     */
    private function extractFormulaVariables(string $formula): array
    {
        if (preg_match_all('/\b([a-zA-Z_][a-zA-Z0-9_]*)\b/', $formula, $matches) === false) {
            return [];
        }

        $excluded = array_merge(self::KNOWN_FUNCTIONS, self::EL_KEYWORDS);
        $excludedSet = array_flip($excluded);

        $variables = [];
        $seen = [];
        foreach ($matches[1] as $token) {
            if (isset($excludedSet[$token]) || isset($seen[$token])) {
                continue;
            }
            $variables[] = $token;
            $seen[$token] = true;
        }

        return $variables;
    }

    /**
     * Extracts references to other computed metrics from a formula.
     * Looks for variables matching health__* or computed__* and maps __ back to .
     *
     * @return list<string>
     */
    private function extractComputedMetricReferences(string $formula): array
    {
        $variables = $this->extractFormulaVariables($formula);
        $refs = [];
        foreach ($variables as $var) {
            if (str_starts_with($var, 'health__') || str_starts_with($var, 'computed__')) {
                $refs[] = str_replace('__', '.', $var);
            }
        }

        return $refs;
    }

    /**
     * Registers math functions in ExpressionLanguage.
     */
    private function registerMathFunctions(): void
    {
        $this->expressionLanguage->addFunction(ExpressionFunction::fromPhp('min'));
        $this->expressionLanguage->addFunction(ExpressionFunction::fromPhp('max'));
        $this->expressionLanguage->addFunction(ExpressionFunction::fromPhp('abs'));
        $this->expressionLanguage->addFunction(ExpressionFunction::fromPhp('sqrt'));
        $this->expressionLanguage->addFunction(ExpressionFunction::fromPhp('log'));
        $this->expressionLanguage->addFunction(ExpressionFunction::fromPhp('log10'));

        $this->expressionLanguage->addFunction(new ExpressionFunction(
            'clamp',
            static fn(string $value, string $min, string $max): string => \sprintf(
                'max(%s, min(%s, %s))',
                $min,
                $max,
                $value,
            ),
            static fn(array $arguments, float $value, float $min, float $max): float => max($min, min($max, $value)),
        ));
    }

    private function mapLevel(string $level): SymbolType
    {
        return match ($level) {
            'class' => SymbolType::Class_,
            'namespace' => SymbolType::Namespace_,
            'project' => SymbolType::Project,
            default => throw new RuntimeException(\sprintf('Invalid computed metric level: "%s"', $level)),
        };
    }

    private function levelToString(SymbolType $level): string
    {
        return match ($level) {
            SymbolType::Class_ => 'class',
            SymbolType::Namespace_ => 'namespace',
            SymbolType::Project => 'project',
            default => $level->value,
        };
    }

    /**
     * Resolves threshold overrides from config, supporting both 'threshold' shorthand
     * and explicit 'warning'/'error' keys with mutual exclusion.
     *
     * @param array<string, mixed> $config
     *
     * @return array{warningThreshold: ?float, errorThreshold: ?float}
     */
    private function resolveThresholdOverrides(array $config, ?float $defaultWarning, ?float $defaultError): array
    {
        $hasThreshold = \array_key_exists('threshold', $config);
        $hasWarning = \array_key_exists('warning', $config);
        $hasError = \array_key_exists('error', $config);

        if ($hasThreshold && ($hasWarning || $hasError)) {
            throw new InvalidArgumentException(
                'Cannot mix "threshold" with "warning"/"error". Use either "threshold" alone (simple mode) or "warning"/"error" (graduated mode).',
            );
        }

        if ($hasThreshold) {
            $value = $this->resolveThreshold($config['threshold']);

            // threshold: null means "not set" — fall back to defaults (consistent with ThresholdParser)
            if ($value === null) {
                return ['warningThreshold' => $defaultWarning, 'errorThreshold' => $defaultError];
            }

            return ['warningThreshold' => $value, 'errorThreshold' => $value];
        }

        return [
            'warningThreshold' => $hasWarning ? $this->resolveThreshold($config['warning']) : $defaultWarning,
            'errorThreshold' => $hasError ? $this->resolveThreshold($config['error']) : $defaultError,
        ];
    }

    private function resolveThreshold(mixed $value): ?float
    {
        if ($value === null) {
            return null;
        }

        if (\is_int($value) || \is_float($value)) {
            return (float) $value;
        }

        return null;
    }
}
