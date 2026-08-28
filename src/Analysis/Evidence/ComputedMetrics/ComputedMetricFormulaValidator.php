<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\ComputedMetrics;

use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Definition\ComputedMetricDefinition;
use Symfony\Component\ExpressionLanguage\SyntaxError;

/**
 * Validates computed metric definitions: formula syntax, level coverage,
 * circular dependencies, and cross-metric references.
 */
final class ComputedMetricFormulaValidator
{
    private readonly ComputedMetricExpression $expression;

    public function __construct()
    {
        $this->expression = new ComputedMetricExpression();
    }

    /**
     * Runs all validations on the given definitions.
     *
     * @param list<ComputedMetricDefinition> $definitions
     *
     * @throws ComputedMetricConfigurationException If any validation fails
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

                try {
                    $this->expression->parse($formula);
                } catch (SyntaxError $e) {
                    $levelKey = $level->value;

                    throw new ComputedMetricConfigurationException(\sprintf(
                        'Invalid formula syntax for computed metric "%s" at level "%s": %s (formula: %s)',
                        $definition->name,
                        $levelKey,
                        $e->getMessage(),
                        $formula,
                    ));
                }

                $this->expression->assertEveryAccessIsALiteralIndex($formula, $definition->name);
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
                    $levelKey = $level->value;

                    throw new ComputedMetricConfigurationException(\sprintf(
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

                throw new ComputedMetricConfigurationException(\sprintf(
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
     * Validates that all formula references to health.* or computed.* correspond to existing definitions.
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
                        throw new ComputedMetricConfigurationException(\sprintf(
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
     * The other computed metrics a formula reads.
     *
     * @return list<string>
     */
    private function extractComputedMetricReferences(string $formula): array
    {
        return $this->expression->computedReferencesOf($formula);
    }

}
