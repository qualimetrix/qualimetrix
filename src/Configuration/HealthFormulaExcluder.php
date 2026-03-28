<?php

declare(strict_types=1);

namespace Qualimetrix\Configuration;

use Psr\Log\LoggerInterface;
use Qualimetrix\Core\ComputedMetric\ComputedMetricDefinition;

/**
 * Filters out excluded health dimensions and rebuilds the health.overall
 * formula with normalized weights when dimensions are excluded.
 */
final readonly class HealthFormulaExcluder
{
    public function __construct(
        private LoggerInterface $logger,
    ) {}

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
            if (str_starts_with($definition->name, 'health.') && $definition->name !== 'health.overall') {
                $knownDimensions[$definition->name] = true;
            }
        }

        foreach ($excludedNames as $name) {
            if ($name !== 'health.overall' && !isset($knownDimensions[$name])) {
                $this->logger->warning('Unknown health dimension in --exclude-health: {dimension}. Known dimensions: {known}', [
                    'dimension' => $name,
                    'known' => implode(', ', array_keys($knownDimensions)),
                ]);
            }
        }

        // Filter out excluded dimensions
        $filtered = [];
        $overallIndex = null;

        foreach ($definitions as $definition) {
            if (isset($excludedSet[$definition->name])) {
                continue;
            }

            if ($definition->name === 'health.overall') {
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
        if (preg_match_all('/\((\w+)\s*\?\?\s*\d+\)\s*\*\s*([\d.]+)/', $formula, $matches, \PREG_SET_ORDER)) {
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
