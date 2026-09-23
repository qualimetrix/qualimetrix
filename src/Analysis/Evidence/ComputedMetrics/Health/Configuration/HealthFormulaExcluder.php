<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Configuration;

use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\RefusedPosition;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Configuration\HealthFormulaExclusionInterface;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Definition\ComputedMetricDefinition;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Definition\HealthDimension;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Evaluation\ComputedMetricExpression;

/**
 * Filters out excluded health dimensions and rebuilds the health.overall
 * formula with normalized weights when dimensions are excluded.
 */
final readonly class HealthFormulaExcluder implements HealthFormulaExclusionInterface
{
    private ComputedMetricExpression $expression;

    public function __construct()
    {
        $this->expression = new ComputedMetricExpression();
    }

    /**
     * Filters out excluded health dimensions and rebuilds health.overall formula
     * with normalized weights when dimensions are excluded.
     *
     * @param list<ComputedMetricDefinition> $definitions
     * @param list<string> $excludedDimensions
     *
     * @return list<ComputedMetricDefinition>
     */
    public function applyExcludeHealth(array $definitions, array $excludedDimensions): array
    {
        if ($excludedDimensions === []) {
            return $definitions;
        }

        $excludedNames = $this->normalizeAndValidateDimensions($definitions, $excludedDimensions);
        $excludedSet = array_flip($excludedNames);
        [$filtered, $overallIndex] = $this->filterDefinitions($definitions, $excludedSet);

        if ($overallIndex === null) {
            return $filtered;
        }

        return $this->replaceOverall($filtered, $overallIndex, $excludedSet);
    }

    /**
     * @param list<ComputedMetricDefinition> $definitions
     * @param list<string> $excludedDimensions
     *
     * @return list<string>
     */
    private function normalizeAndValidateDimensions(array $definitions, array $excludedDimensions): array
    {
        $excludedNames = array_map(
            static fn(string $dimension): string => str_starts_with($dimension, 'health.') ? $dimension : 'health.' . $dimension,
            $excludedDimensions,
        );
        $knownDimensions = $this->knownDimensions($definitions);
        $unknownDimensions = array_values(array_filter(
            $excludedNames,
            static fn(string $name): bool => $name !== HealthDimension::Overall->value && !isset($knownDimensions[$name]),
        ));

        if ($unknownDimensions === []) {
            return $excludedNames;
        }

        // The rejected element, not the "exclude_health" key, is what is
        // wrong here — the position names the key, the element and the
        // accepted names live in the summary, per the same rule
        // `02-computed-metric-keys.md` §2 gives every list-element refusal.
        throw ConfigurationRefusal::atResolvedKey(
            RefusedPosition::open(['exclude_health'], 'exclude_health'),
            \sprintf(
                'Unknown health dimension(s) in --exclude-health: %s. Valid dimensions: %s',
                implode(', ', $unknownDimensions),
                implode(', ', array_keys($knownDimensions)),
            ),
        );
    }

    /**
     * @param list<ComputedMetricDefinition> $definitions
     *
     * @return array<string, true>
     */
    private function knownDimensions(array $definitions): array
    {
        $known = [];
        foreach ($definitions as $definition) {
            if (str_starts_with($definition->name, 'health.') && $definition->name !== HealthDimension::Overall->value) {
                $known[$definition->name] = true;
            }
        }

        return $known;
    }

    /**
     * @param list<ComputedMetricDefinition> $definitions
     * @param array<string, int> $excludedSet
     *
     * @return array{list<ComputedMetricDefinition>, int|null}
     */
    private function filterDefinitions(array $definitions, array $excludedSet): array
    {
        $filtered = [];
        $overallIndex = null;
        foreach ($definitions as $definition) {
            if (isset($excludedSet[$definition->name])) {
                continue;
            }

            if ($definition->name === HealthDimension::Overall->value) {
                $overallIndex = \count($filtered);
            }

            $filtered[] = $definition;
        }

        return [$filtered, $overallIndex];
    }

    /**
     * @param list<ComputedMetricDefinition> $definitions
     * @param array<string, int> $excludedSet
     *
     * @return list<ComputedMetricDefinition>
     */
    private function replaceOverall(array $definitions, int $overallIndex, array $excludedSet): array
    {
        $rebuilt = $this->rebuildOverallFormula($definitions[$overallIndex], $excludedSet);
        if ($rebuilt === null) {
            unset($definitions[$overallIndex]);
        } else {
            $definitions[$overallIndex] = $rebuilt;
        }

        return array_values($definitions);
    }

    /**
     * Rebuilds the health.overall formula by removing excluded dimensions
     * and normalizing remaining weights proportionally.
     *
     * @param array<string, int> $excludedSet
     */
    private function rebuildOverallFormula(ComputedMetricDefinition $overall, array $excludedSet): ?ComputedMetricDefinition
    {
        $formulas = $overall->formulas;
        $allEmpty = true;

        foreach ($formulas as $level => $formula) {
            $terms = WeightedHealthFormula::termsOf($this->expression, $formula);

            // Auto-renormalization works only on the canonical weighted-sum shape
            // `(m["health.dim"] ?? 75) * 0.NN + ...`. If a user has overridden
            // `health.overall` with a non-canonical formula (e.g. `min(...)`,
            // a conditional, a custom aggregator), parsing yields no weights and
            // silently dropping the level would lose the user's intent. Refuse
            // explicitly so the user can either drop the exclusion or rewrite
            // their custom formula to handle the missing dimension via `??`.
            if ($terms === null) {
                // $overall->name is always "health.overall": the reserved-prefix
                // segment slicing rule (`02-computed-metric-keys.md` §2) is applied
                // by hand rather than through ComputedMetricEntryKeys::nameSegments()
                // — that helper is Root-internal, and this class is Health-internal
                // (see ComputedMetricsInternalTopologyTest's zone DAG).
                throw ConfigurationRefusal::atResolvedKey(
                    RefusedPosition::open(['computed_metrics', 'health', 'overall', 'formulas', $level], $level),
                    \sprintf(
                        'Cannot auto-renormalize "health.overall" at level "%s" after excluding '
                        . 'health dimensions: the custom formula does not match the canonical '
                        . 'weighted-sum shape `(m["health.dimension"] ?? fallback) * weight`. '
                        . 'Either rewrite the custom formula to reference disabled dimensions '
                        . 'via `??` fallbacks, or remove the exclusion. Formula: %s',
                        $level,
                        $formula,
                    ),
                );
            }

            $rebuilt = self::buildWeightedFormula($terms, $excludedSet);

            if ($rebuilt !== null) {
                $formulas[$level] = $rebuilt;
                $allEmpty = false;
            } else {
                unset($formulas[$level]);
            }
        }

        if ($allEmpty) {
            return null;
        }

        return new ComputedMetricDefinition(
            name: $overall->name,
            formulas: $formulas,
            description: $overall->description,
            levels: $overall->levels,
            inverted: $overall->inverted,
            warningThreshold: $overall->warningThreshold,
            errorThreshold: $overall->errorThreshold,
        );
    }

    /**
     * Rebuilds the weighted sum over the dimensions that remain, each keeping
     * the fallback it was written with.
     *
     * The fallback used to be re-emitted as a literal 75 whatever the formula
     * said, so a user's own default was replaced by ours on the way through.
     *
     * @param array<string, array{weight: float, fallback: float}> $terms
     * @param array<string, int> $excludedSet
     */
    private static function buildWeightedFormula(array $terms, array $excludedSet): ?string
    {
        $remaining = array_diff_key($terms, $excludedSet);

        if ($remaining === []) {
            return null;
        }

        $weights = self::normalizedWeights(array_column($remaining, 'weight'));
        $rebuilt = [];

        foreach (array_keys($remaining) as $index => $dimension) {
            $rebuilt[] = \sprintf(
                '(m["%s"] ?? %s) * %s',
                $dimension,
                self::number($remaining[$dimension]['fallback']),
                self::number($weights[$index]),
            );
        }

        return \sprintf('clamp(%s, 0, 100)', implode(' + ', $rebuilt));
    }

    /**
     * Shares that sum to exactly one at the precision they are printed with.
     *
     * Rounding each share on its own leaves the scale short: dropping typing
     * from the namespace formula gives 0.3333 + 0.2222 * 3 = 0.9999, and a
     * subject scoring 100 on every remaining dimension then publishes 99.99.
     * The rounding remainder goes to the widest share, where it is the smallest
     * relative distortion, instead of being dropped.
     *
     * @param list<float> $weights
     *
     * @return list<float>
     */
    private static function normalizedWeights(array $weights): array
    {
        $total = array_sum($weights);
        $shares = array_map(static fn(float $weight): float => round($weight / $total, 4), $weights);

        $widest = 0;

        foreach ($shares as $index => $share) {
            if ($share > $shares[$widest]) {
                $widest = $index;
            }
        }

        $shares[$widest] = round($shares[$widest] + (1.0 - array_sum($shares)), 4);

        return array_values($shares);
    }

    /** A float printed the way a formula reads it, without a trailing `.0`. */
    private static function number(float $value): string
    {
        $trimmed = rtrim(rtrim(\sprintf('%.4F', $value), '0'), '.');

        return $trimmed === '' || $trimmed === '-' ? '0' : $trimmed;
    }
}
