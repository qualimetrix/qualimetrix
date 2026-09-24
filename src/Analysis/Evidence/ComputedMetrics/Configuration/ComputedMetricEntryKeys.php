<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\ComputedMetrics\Configuration;

use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Definition\HealthDimension;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionKeySet;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionShape;
use Qualimetrix\Core\Symbol\SymbolLevel;

/**
 * The declared vocabulary of one `computed_metrics:` entry, at every depth
 * that vocabulary is closed.
 *
 * Depth 0 (the metric name) is deliberately absent: it is an open namespace
 * described by a grammar plus five refusals
 * ({@see \Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Definition\ComputedMetricDefinition}),
 * not a set — see `02-computed-metric-keys.md` §2. The `health.*` half of
 * depth 0 is closed on six names and is declared here too, because it is a
 * set, unlike the general name.
 */
final class ComputedMetricEntryKeys
{
    /**
     * The levels this capability reports at, named once for both readers of
     * the fact: the `formula:` shorthand writes one key per level, and the
     * traversal refuses every `formulas:` key outside this list.
     *
     * The single source moved here from
     * {@see \Qualimetrix\Analysis\Evidence\ComputedMetrics\ComputedMetricOverrideReader}:
     * before this class existed, that reader's private constant was the only
     * source for two readers inside one file; after it, four readers ask it
     * (the `formula:` shorthand, `mapLevel()`, the `formulas:` key walk, and
     * the `levels:` element walk), and a second private copy would drift the
     * moment one of them changed.
     *
     * @var list<SymbolLevel>
     */
    public const array REPORTING_LEVELS = [SymbolLevel::Class_, SymbolLevel::Namespace_, SymbolLevel::Project];

    /**
     * The nine keys a `computed_metrics:` entry answers for at depth 1.
     *
     * The forms below are stated, not consumed here: the traversal
     * ({@see ComputedMetricEntryKeyRecognition}) asks only whether a name is
     * known, and each leaf's form is judged by
     * {@see \Qualimetrix\Analysis\Evidence\ComputedMetrics\ComputedMetricOverrideReader}
     * in wording that names the metric. Consuming them here would raise a
     * second, more general refusal one step before the specific one, so the
     * declaration stays a statement of the reader's contract rather than a
     * second judge of it.
     */
    public static function acceptedEntryKeys(): RuleOptionKeySet
    {
        return RuleOptionKeySet::of([
            'description' => RuleOptionShape::text()->orNull(),
            'enabled' => RuleOptionShape::boolean()->orNull(),
            'error' => RuleOptionShape::signedNumber()->orNull(),
            'formula' => RuleOptionShape::text()->orNull(),
            'formulas' => RuleOptionShape::block()->orNull(),
            'inverted' => RuleOptionShape::boolean()->orNull(),
            'levels' => RuleOptionShape::listOf(RuleOptionShape::text())->orNull(),
            'threshold' => RuleOptionShape::signedNumber()->orNull(),
            'warning' => RuleOptionShape::signedNumber()->orNull(),
        ]);
    }

    /** The three level words accepted as keys inside `formulas:`. */
    public static function acceptedFormulaKeys(): RuleOptionKeySet
    {
        return RuleOptionKeySet::of([
            'class' => RuleOptionShape::text()->orNull(),
            'namespace' => RuleOptionShape::text()->orNull(),
            'project' => RuleOptionShape::text()->orNull(),
        ]);
    }

    /**
     * The six short `health.*` names a reserved-prefix entry may name,
     * read from {@see HealthDimension::cases()} rather than kept as a second
     * literal list that could drift from it.
     *
     * @return list<string>
     */
    public static function acceptedHealthNames(): array
    {
        $names = array_map(
            static fn(HealthDimension $dimension): string => $dimension->shortName(),
            HealthDimension::cases(),
        );
        sort($names);

        return $names;
    }

    /**
     * The one slicing rule used at every depth a refusal names this entry's
     * name by: the reserved `health.` prefix is always its own segment,
     * because it names a closed subspace, while a user-chosen metric name is
     * one opaque segment, because there is no structure inside it to slice.
     * Without this rule the same name would be cut differently depending on
     * which depth the refusal happened to occur at.
     *
     * @return list<string>
     */
    public static function nameSegments(string $metricName): array
    {
        if (str_starts_with($metricName, 'health.')) {
            return ['computed_metrics', 'health', substr($metricName, 7)];
        }

        return ['computed_metrics', $metricName];
    }
}
