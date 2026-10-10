<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\ComputedMetrics;

use Closure;

use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Configuration\ComputedMetricAuthorship;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Configuration\ComputedMetricRefusalWording;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Configuration\ComputedMetricValueForm;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Definition\ComputedMetricDefinition;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Evaluation\ComputedMetricExpression;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricName;
use Qualimetrix\Core\Symbol\SymbolLevel;
use ReflectionClass;

/**
 * Validates computed metric definitions: formula syntax, level coverage,
 * circular dependencies, cross-metric references and the levels they are
 * read at, and that every other addressed metric key exists in the catalog.
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
     * @param ComputedMetricAuthorship $authorship which layers wrote each definition, for the refusal to name
     *
     * @throws ConfigurationRefusal If any validation fails
     */
    public function validate(array $definitions, ComputedMetricAuthorship $authorship = new ComputedMetricAuthorship()): void
    {
        $this->validateFormulaSyntax($definitions, $authorship);
        $this->validateFormulaCoverage($definitions, $authorship);
        $this->validateCircularDependencies($definitions, $authorship);
        $this->validateComputedMetricReferences($definitions, $authorship);
        $this->validateComputedMetricReferenceLevels($definitions, $authorship);
        $this->validateMetricKeyExistence($definitions, $authorship);
    }

    /**
     * Validates that all formula strings are syntactically valid ExpressionLanguage expressions.
     *
     * @param list<ComputedMetricDefinition> $definitions
     */
    private function validateFormulaSyntax(array $definitions, ComputedMetricAuthorship $authorship): void
    {
        foreach ($definitions as $definition) {
            foreach ($definition->levels as $level) {
                $formula = $definition->getFormulaForLevel($level);
                if ($formula === null) {
                    continue; // Coverage validation handles missing formulas
                }

                $levelKey = $level->value;

                $refusal = ComputedMetricValueForm::formulaRefusal($this->expression, $definition->name, $levelKey, $formula);
                if ($refusal !== null) {
                    throw $authorship->refuseFormula($definition, $levelKey, $refusal);
                }
            }
        }
    }

    /**
     * Validates that each level in a definition has a resolvable formula.
     *
     * @param list<ComputedMetricDefinition> $definitions
     */
    private function validateFormulaCoverage(array $definitions, ComputedMetricAuthorship $authorship): void
    {
        foreach ($definitions as $definition) {
            foreach ($definition->levels as $level) {
                $formula = $definition->getFormulaForLevel($level);
                if ($formula === null) {
                    $levelKey = $level->value;

                    throw $authorship->refuseMissingFormula(
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
    private function validateCircularDependencies(array $definitions, ComputedMetricAuthorship $authorship): void
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

        $visit = function (string $node, array $path) use (&$visit, &$visited, &$inStack, $graph, $authorship): void {
            if (isset($inStack[$node])) {
                $cycleStart = array_search($node, $path, true);
                \assert($cycleStart !== false);
                $cycle = array_values(\array_slice($path, (int) $cycleStart));
                $cycle[] = $node;

                throw $authorship->refuseAcross($cycle, ComputedMetricRefusalWording::circularDependency($cycle));
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
    private function validateComputedMetricReferences(array $definitions, ComputedMetricAuthorship $authorship): void
    {
        $nameSet = [];
        foreach ($definitions as $definition) {
            $nameSet[$definition->name] = true;
        }

        foreach ($definitions as $definition) {
            foreach ($definition->formulas as $level => $formula) {
                foreach ($this->extractComputedMetricReferences($formula) as $ref) {
                    if (!isset($nameSet[$ref])) {
                        throw $authorship->refuseFormula(
                            $definition,
                            (string) $level,
                            ComputedMetricRefusalWording::referencesUnknownMetric($definition->name, $ref, $formula),
                        );
                    }
                }
            }
        }
    }

    /**
     * Refuses a formula that would read another computed metric at a level
     * that metric does not declare.
     *
     * A computed metric is published only at its own levels, so such a read
     * finds it on no symbol and the reading metric is published nowhere. Each
     * level is judged by the formula it actually runs — `project` inherits the
     * `namespace` formula — and a read behind `??` counts only where the
     * fallback is reached. A read only one ternary branch or the right side of
     * `and`/`or` makes is not refused: which branch runs is known per symbol,
     * and the evaluator skips a symbol that reaches it. A measured key counts
     * as present here: which levels
     * carry it is known only once a run has measured, and the evaluator
     * refuses it then.
     *
     * @param list<ComputedMetricDefinition> $definitions
     */
    private function validateComputedMetricReferenceLevels(array $definitions, ComputedMetricAuthorship $authorship): void
    {
        $byName = [];
        foreach ($definitions as $definition) {
            $byName[$definition->name] = $definition;
        }

        foreach ($definitions as $definition) {
            foreach ($definition->levels as $level) {
                $formula = $definition->getFormulaForLevel($level);
                if ($formula === null) {
                    continue;
                }

                $unpublished = $this->expression->missingKeysOf(
                    $formula,
                    static fn(string $key): bool => !isset($byName[$key]) || $byName[$key]->hasLevel($level),
                );

                if ($unpublished !== []) {
                    self::refuseUnpublishedReferences($definition->name, $unpublished, $byName, $level->value, $formula, $authorship);
                }
            }
        }
    }

    /**
     * @param non-empty-list<string> $unpublished
     * @param array<string, ComputedMetricDefinition> $byName
     */
    private static function refuseUnpublishedReferences(
        string $definitionName,
        array $unpublished,
        array $byName,
        string $level,
        string $formula,
        ComputedMetricAuthorship $authorship,
    ): never {
        $publishedAt = [];
        foreach ($unpublished as $reference) {
            $publishedAt[$reference] = [];
            foreach ($byName[$reference]->reportingLevels() as $published) {
                $publishedAt[$reference][] = $published->value;
            }
        }

        // Every metric of the relation: the reader's formula and the levels
        // the read metrics declare may come from different layers.
        throw $authorship->refuseAcross(
            [$definitionName, ...$unpublished],
            ComputedMetricRefusalWording::readsComputedMetricNotPublishedAtLevel($definitionName, $publishedAt, $level, $formula),
        );
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
    private function validateMetricKeyExistence(array $definitions, ComputedMetricAuthorship $authorship): void
    {
        foreach ($definitions as $definition) {
            foreach ($definition->formulas as $level => $formula) {
                $this->validateFormulaMetricKeys($definition, (string) $level, $formula, $authorship);
            }
        }
    }

    /**
     * Refuses a formula that names a metric no symbol at the level carries.
     *
     * Beside the four checks above rather than at the site that measures it:
     * this class is where a computed-metric formula is declared unacceptable,
     * and a second author of the same refusal would be a second spelling of
     * exit code 3 for the same mistake. Only the evidence differs — a key
     * missing from the catalog is knowable from the configuration alone, while
     * a key no symbol publishes is only knowable once a run has measured.
     *
     * @param list<string> $keys as the formula spells them
     * @param Closure(ComputedMetricDefinition, SymbolLevel, string): ConfigurationRefusal $refuseFormula
     *
     * @throws ConfigurationRefusal
     */
    public static function refuseMetricsAbsentAtLevel(
        ComputedMetricDefinition $definition,
        array $keys,
        SymbolLevel $level,
        string $formula,
        Closure $refuseFormula,
    ): never {
        throw $refuseFormula(
            $definition,
            $level,
            ComputedMetricRefusalWording::referencesMetricAbsentAtLevel($definition->name, $keys, $level->value, $formula),
        );
    }

    private function validateFormulaMetricKeys(ComputedMetricDefinition $definition, string $level, string $formula, ComputedMetricAuthorship $authorship): void
    {
        foreach ($this->expression->keysOf($formula) as $key) {
            $this->assertKeyIsCatalogued($definition, $level, $key, $formula, $authorship);
        }
    }

    private function assertKeyIsCatalogued(
        ComputedMetricDefinition $definition,
        string $level,
        string $key,
        string $formula,
        ComputedMetricAuthorship $authorship,
    ): void {
        if (ComputedMetricExpression::isComputedReference($key)) {
            return; // Cross-references, validated above against declared definitions.
        }

        if ($this->existsInCatalog($key)) {
            return;
        }

        throw $authorship->refuseFormula(
            $definition,
            $level,
            ComputedMetricRefusalWording::referencesUnknownMetricKey($definition->name, $key, $formula),
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
}
