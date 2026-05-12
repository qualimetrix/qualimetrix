<?php

declare(strict_types=1);

namespace Qualimetrix\Configuration;

use InvalidArgumentException;
use Qualimetrix\Core\ComputedMetric\ComputedMetricDefinition;
use Qualimetrix\Core\ComputedMetric\HealthDimension;

/**
 * Filters out excluded health dimensions and rebuilds the health.overall
 * formula with normalized weights when dimensions are excluded.
 */
final readonly class HealthFormulaExcluder
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

        // Normalize dimension names (allow both "typing" and "health.typing")
        $excludedNames = array_map(
            static fn(string $dim): string => str_starts_with($dim, 'health.') ? $dim : 'health.' . $dim,
            $excludedDimensions,
        );
        $excludedSet = array_flip($excludedNames);

        // Validate dimension names -- warn on unknown
        $knownDimensions = [];
        foreach ($definitions as $definition) {
            if (str_starts_with($definition->name, 'health.') && $definition->name !== HealthDimension::Overall->value) {
                $knownDimensions[$definition->name] = true;
            }
        }

        $unknownDimensions = [];
        foreach ($excludedNames as $name) {
            if ($name !== HealthDimension::Overall->value && !isset($knownDimensions[$name])) {
                $unknownDimensions[] = $name;
            }
        }

        if ($unknownDimensions !== []) {
            throw new InvalidArgumentException(\sprintf(
                'Unknown health dimension(s) in --exclude-health: %s. Valid dimensions: %s',
                implode(', ', $unknownDimensions),
                implode(', ', array_keys($knownDimensions)),
            ));
        }

        // Filter out excluded dimensions
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

        // Rebuild health.overall formula with normalized weights if some dimensions were excluded
        if ($overallIndex !== null) {
            $rebuilt = $this->rebuildOverallFormula($filtered[$overallIndex], $excludedSet);
            if ($rebuilt !== null) {
                $filtered[$overallIndex] = $rebuilt;
            } else {
                // All sub-dimensions excluded -- remove health.overall entirely
                unset($filtered[$overallIndex]);
            }
        }

        return array_values($filtered);
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
