<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\ComputedMetrics;

use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\RefusedPosition;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Configuration\ComputedMetricEntryKeys;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Configuration\ComputedMetricRefusalWording;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Definition\ComputedMetricDefinition;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Evaluation\ComputedMetricExpression;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricName;
use ReflectionClass;
use Symfony\Component\ExpressionLanguage\SyntaxError;
use Throwable;

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
     * @throws ConfigurationRefusal If any validation fails
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

                $levelKey = $level->value;

                try {
                    $this->expression->parse($formula);
                } catch (SyntaxError $e) {
                    // Narrow on purpose: the try body is one call, and SyntaxError
                    // is the exact family that call's contract names. Widening this
                    // to Throwable/InvalidArgumentException would let a product
                    // defect from inside ExpressionLanguage masquerade as a user
                    // refusal.
                    throw $this->refuse(
                        $definition->name,
                        $levelKey,
                        ComputedMetricRefusalWording::invalidFormulaSyntax($definition->name, $levelKey, $e->getMessage(), $formula),
                        $e,
                    );
                }

                if (!$this->expression->everyAccessIsALiteralIndex($formula)) {
                    throw $this->refuse(
                        $definition->name,
                        $levelKey,
                        ComputedMetricRefusalWording::everyAccessMustBeALiteralIndex($definition->name, $formula),
                    );
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

                    throw $this->refuse(
                        $definition->name,
                        $levelKey,
                        ComputedMetricRefusalWording::noFormulaForLevel($definition->name, $levelKey),
                    );
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
                $cycle = array_values(\array_slice($path, (int) $cycleStart));
                $cycle[] = $node;

                // The position names the node the cycle closed on — the one
                // already in $inStack — because a carrier has one position and
                // the chain is a fact about the whole set, not one metric's
                // entry; the full chain is named in the summary instead.
                throw ConfigurationRefusal::atResolvedKey(
                    RefusedPosition::open(ComputedMetricEntryKeys::nameSegments($node), $node),
                    ComputedMetricRefusalWording::circularDependency($cycle),
                );
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
                        throw ConfigurationRefusal::atResolvedKey(
                            RefusedPosition::open(ComputedMetricEntryKeys::nameSegments($definition->name), $definition->name),
                            ComputedMetricRefusalWording::referencesUnknownMetric($definition->name, $ref, $formula),
                        );
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
                $this->validateFormulaMetricKeys($definition->name, $formula);
            }
        }
    }

    private function validateFormulaMetricKeys(string $definitionName, string $formula): void
    {
        foreach ($this->expression->keysOf($formula) as $key) {
            $this->assertKeyIsCatalogued($definitionName, $key, $formula);
        }
    }

    private function assertKeyIsCatalogued(string $definitionName, string $key, string $formula): void
    {
        if (str_starts_with($key, 'health.') || str_starts_with($key, 'computed.')) {
            return; // Cross-references, validated above against declared definitions.
        }

        if ($this->existsInCatalog($key)) {
            return;
        }

        throw ConfigurationRefusal::atResolvedKey(
            RefusedPosition::open(ComputedMetricEntryKeys::nameSegments($definitionName), $definitionName),
            ComputedMetricRefusalWording::referencesUnknownMetricKey($definitionName, $key, $formula),
        );
    }

    /**
     * Whether a key exists once its aggregation suffix (if any) is stripped
     * by {@see MetricName::base()}, whose suffix list comes from
     * {@see \Qualimetrix\Analysis\Evidence\Measurement\Contract\AggregationStrategy}.
     *
     * The catalog is read via reflection over {@see MetricName}'s public
     * constants rather than a hand-kept list, so it cannot drift from the
     * names collectors actually use. Every base key a collector emits (outside
     * `health.*`/`computed.*`, which this validator's fourth check already
     * owns) has a constant here, so the constant list is the catalog rather
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

    private function refuse(string $metricName, string $level, string $summary, ?Throwable $previous = null): ConfigurationRefusal
    {
        return ConfigurationRefusal::atResolvedKey(
            RefusedPosition::open([...ComputedMetricEntryKeys::nameSegments($metricName), 'formulas', $level], $level),
            $summary,
            previous: $previous,
        );
    }
}
