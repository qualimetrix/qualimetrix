<?php

declare(strict_types=1);

namespace Qualimetrix\Configuration;

use Qualimetrix\Core\ComputedMetric\ComputedMetricDefinition;
use Qualimetrix\Core\Symbol\SymbolType;
use RuntimeException;
use Symfony\Component\ExpressionLanguage\ExpressionFunction;
use Symfony\Component\ExpressionLanguage\ExpressionLanguage;
use Symfony\Component\ExpressionLanguage\SyntaxError;

/**
 * Validates computed metric definitions: formula syntax, level coverage,
 * circular dependencies, and cross-metric references.
 */
final class ComputedMetricFormulaValidator
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
     * Runs all validations on the given definitions.
     *
     * @param list<ComputedMetricDefinition> $definitions
     *
     * @throws RuntimeException If any validation fails
     */
    public function validate(array $definitions): void
    {
        $this->validateFormulaSyntax($definitions);
        $this->validateFormulaCoverage($definitions);
        $this->validateCircularDependencies($definitions);
        $this->validateComputedMetricReferences($definitions);
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
        foreach ($definitions as $definition) {
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

    private function levelToString(SymbolType $level): string
    {
        return match ($level) {
            SymbolType::Class_ => 'class',
            SymbolType::Namespace_ => 'namespace',
            SymbolType::Project => 'project',
            default => $level->value,
        };
    }
}
