<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\ComputedMetrics;

use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Definition\ComputedMetricDefinition;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Evaluation\ComputedMetricExpression;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricName;
use ReflectionClass;
use Symfony\Component\ExpressionLanguage\SyntaxError;

/**
 * Validates computed metric definitions: formula syntax, level coverage,
 * circular dependencies, cross-metric references, and that every other
 * addressed metric key exists in the catalog.
 */
final class ComputedMetricFormulaValidator
{
    private readonly ComputedMetricExpression $expression;

    /** @var array<string, true>|null */
    private static ?array $catalogBaseKeys = null;

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
        $this->validateMetricKeyExistence($definitions);
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

                if (!$this->expression->everyAccessIsALiteralIndex($formula)) {
                    throw new ComputedMetricConfigurationException(\sprintf(
                        'Computed metric "%s" reaches "m" by something other than a quoted metric key, which makes'
                        . ' the key unverifiable. Write every access as m["<metric key>"]. Formula: %s',
                        $definition->name,
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

    /**
     * Validates that every metric key a formula addresses, other than a
     * `health.*`/`computed.*` cross-reference (checked above), names a metric
     * the product actually publishes.
     *
     * @param list<ComputedMetricDefinition> $definitions
     */
    private function validateMetricKeyExistence(array $definitions): void
    {
        foreach ($definitions as $definition) {
            foreach ($definition->formulas as $formula) {
                foreach ($this->expression->keysOf($formula) as $key) {
                    if (str_starts_with($key, 'health.') || str_starts_with($key, 'computed.')) {
                        continue; // Cross-references, validated above against declared definitions.
                    }

                    if (!$this->existsInCatalog($key)) {
                        throw new ComputedMetricConfigurationException(\sprintf(
                            'Computed metric "%s" references unknown metric key "%s" in formula: %s',
                            $definition->name,
                            $key,
                            $formula,
                        ));
                    }
                }
            }
        }
    }

    /**
     * Whether a key exists once its aggregation suffix (if any) is stripped
     * by {@see MetricName::base()}, whose suffix list comes from
     * {@see \Qualimetrix\Analysis\Evidence\Measurement\Contract\AggregationStrategy}.
     *
     * The catalog is read via reflection over {@see MetricName}'s public
     * constants rather than a hand-kept list, so it cannot drift from the
     * names collectors actually use.
     * `X9-gate-holes/enumeration-c1-catalog.tsv` re-measured the
     * runtime-published universe against these constants and found the gap
     * empty — every base key a collector emits (outside
     * `health.*`/`computed.*`, which this validator's fourth check already
     * owns) has a constant here — so the constant list is the catalog rather
     * than a narrower stand-in for it. A future collector that publishes a
     * key with no `MetricName` constant would reopen that gap and this check
     * would need to read the wider, republished universe instead.
     */
    private function existsInCatalog(string $key): bool
    {
        return isset(self::catalogBaseKeys()[MetricName::base($key)]);
    }

    /** @return array<string, true> */
    private static function catalogBaseKeys(): array
    {
        if (self::$catalogBaseKeys !== null) {
            return self::$catalogBaseKeys;
        }

        $keys = [];
        foreach ((new ReflectionClass(MetricName::class))->getReflectionConstants() as $constant) {
            if (!$constant->isPublic()) {
                continue;
            }

            $value = $constant->getValue();
            if (\is_string($value)) {
                $keys[$value] = true;
            }
        }

        return self::$catalogBaseKeys = $keys;
    }

}
