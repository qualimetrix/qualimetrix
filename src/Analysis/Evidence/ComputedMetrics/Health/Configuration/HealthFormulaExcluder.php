<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Configuration;

use InvalidArgumentException;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Configuration\HealthFormulaExclusionInterface;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Definition\ComputedMetricDefinition;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Definition\HealthDimension;

/**
 * Filters out excluded health dimensions and rebuilds the health.overall
 * formula with normalized weights when dimensions are excluded.
 */
final readonly class HealthFormulaExcluder implements HealthFormulaExclusionInterface
{
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

        throw new InvalidArgumentException(\sprintf(
            'Unknown health dimension(s) in --exclude-health: %s. Valid dimensions: %s',
            implode(', ', $unknownDimensions),
            implode(', ', array_keys($knownDimensions)),
        ));
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
            $weights = $this->parseWeightsFromFormula($formula);

            // Auto-renormalization works only on the canonical weighted-sum shape
            // `(health__dim ?? 75) * 0.NN + ...`. If a user has overridden
            // `health.overall` with a non-canonical formula (e.g. `min(...)`,
            // a conditional, a custom aggregator), parsing yields no weights and
            // silently dropping the level would lose the user's intent. Refuse
            // explicitly so the user can either drop the exclusion or rewrite
            // their custom formula to handle the missing dimension via `??`.
            if ($weights === []) {
                throw new InvalidArgumentException(\sprintf(
                    'Cannot auto-renormalize "health.overall" at level "%s" after excluding '
                    . 'health dimensions: the custom formula does not match the canonical '
                    . 'weighted-sum shape `(health__dimension ?? fallback) * weight`. '
                    . 'Either rewrite the custom formula to reference disabled dimensions '
                    . 'via `??` fallbacks, or remove the exclusion. Formula: %s',
                    $level,
                    $formula,
                ));
            }

            $rebuilt = $this->buildWeightedFormula($weights, $excludedSet);

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
     * Parses dimension weights from a health.overall formula string.
     *
     * Expected pattern: `(health__dimension ?? 75) * 0.25`
     *
     * @return array<string, float> dimension name => weight
     */
    private function parseWeightsFromFormula(string $formula): array
    {
        $weights = [];

        // Match patterns like: (health__complexity ?? 75) * 0.30
        if (preg_match_all('/\((\w+)\s*\?\?\s*\d+\)\s*\*\s*([\d.]+)/', $formula, $matches, \PREG_SET_ORDER) !== 0) {
            foreach ($matches as $match) {
                $varName = str_replace('__', '.', $match[1]);
                $weights[$varName] = (float) $match[2];
            }
        }

        return $weights;
    }

    /**
     * Builds a weighted formula string with normalized weights after exclusions.
     *
     * @param array<string, float> $weights
     * @param array<string, int> $excludedSet
     */
    private function buildWeightedFormula(array $weights, array $excludedSet): ?string
    {
        // Filter out excluded dimensions
        $remaining = [];
        foreach ($weights as $dim => $weight) {
            if (!isset($excludedSet[$dim])) {
                $remaining[$dim] = $weight;
            }
        }

        if ($remaining === []) {
            return null;
        }

        // Normalize weights to sum to 1.0
        $totalWeight = array_sum($remaining);
        $terms = [];

        foreach ($remaining as $dim => $weight) {
            $normalizedWeight = round($weight / $totalWeight, 4);
            $varName = str_replace('.', '__', $dim);
            $terms[] = \sprintf('(%s ?? 75) * %s', $varName, $normalizedWeight);
        }

        return \sprintf('clamp(%s, 0, 100)', implode(' + ', $terms));
    }
}
