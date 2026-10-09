<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Configuration;

use Closure;
use LogicException;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Configuration\HealthFormulaExclusionInterface;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Definition\ComputedMetricDefinition;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Definition\HealthDimension;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Evaluation\ComputedMetricExpression;

/**
 * Filters out excluded health dimensions and rebuilds the health.overall
 * formula while retaining authored weights for runtime renormalization.
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
     * while retaining authored weights for runtime renormalization.
     *
     * @param list<ComputedMetricDefinition> $definitions
     * @param list<string> $excludedDimensions
     * @param Closure(string, string): ConfigurationRefusal $refuseOverall
     *
     * @return list<ComputedMetricDefinition>
     */
    public function applyExcludeHealth(array $definitions, array $excludedDimensions, Closure $refuseOverall): array
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

        return $this->replaceOverall($filtered, $overallIndex, $excludedSet, $refuseOverall);
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

        // The caller judges every name in the words of the layer that wrote
        // it; reaching here means a caller skipped that.
        throw new LogicException(\sprintf(
            'Cannot exclude unknown health dimension(s) %s; known: %s.',
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
     * @param Closure(string, string): ConfigurationRefusal $refuseOverall
     *
     * @return list<ComputedMetricDefinition>
     */
    private function replaceOverall(array $definitions, int $overallIndex, array $excludedSet, Closure $refuseOverall): array
    {
        $rebuilt = $this->rebuildOverallFormula($definitions[$overallIndex], $excludedSet, $refuseOverall);
        if ($rebuilt === null) {
            unset($definitions[$overallIndex]);
        } else {
            $definitions[$overallIndex] = $rebuilt;
        }

        return array_values($definitions);
    }

    /**
     * Rebuilds the health.overall formula by removing excluded dimensions
     * while preserving the remaining order and weights.
     *
     * @param array<string, int> $excludedSet
     * @param Closure(string, string): ConfigurationRefusal $refuseOverall
     */
    private function rebuildOverallFormula(ComputedMetricDefinition $overall, array $excludedSet, Closure $refuseOverall): ?ComputedMetricDefinition
    {
        $formulas = $overall->formulas;
        $allEmpty = true;

        foreach ($formulas as $level => $formula) {
            $terms = WeightedHealthFormula::termsOf($this->expression, $formula);

            if ($terms === null) {
                throw $refuseOverall(
                    (string) $level,
                    \sprintf(
                        'Cannot auto-renormalize "health.overall" at level "%s" after excluding '
                        . 'health dimensions: the custom formula does not match the canonical '
                        . 'ordered weighted_mean shape `weighted_mean(m["health.dimension"], weight, ...)`. '
                        . 'Either rewrite the custom formula to reference disabled dimensions '
                        . 'through weighted_mean, or remove the exclusion. Formula: %s',
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
            applicability: $overall->applicability,
        );
    }

    /**
     * Keep authored order and weights: weighted_mean renormalizes only the
     * participating values, so pre-normalizing changes floating-point results.
     *
     * @param array<string, array{weight: float}> $terms
     * @param array<string, int> $excludedSet
     */
    private static function buildWeightedFormula(array $terms, array $excludedSet): ?string
    {
        $remaining = array_diff_key($terms, $excludedSet);
        if ($remaining === []) {
            return null;
        }
        $rebuilt = [];
        foreach ($remaining as $dimension => $term) {
            $rebuilt[] = \sprintf('m["%s"], %s', $dimension, json_encode($term['weight'], \JSON_THROW_ON_ERROR | \JSON_PRESERVE_ZERO_FRACTION));
        }

        return \sprintf('clamp(weighted_mean(%s), 0, 100)', implode(', ', $rebuilt));
    }
}
