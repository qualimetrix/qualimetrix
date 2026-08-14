<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Contract\DrillDown;

use Generator;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Definition\ComputedMetricDefinitionCatalogInterface;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Definition\HealthDimension;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Contract\Metadata\HealthMetricMetadataProviderInterface;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Contract\Score\HealthScore;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Metadata\HealthDimensionCatalog;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Score\ContributorRanker;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricBag;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricRepositoryInterface;
use Qualimetrix\Core\Symbol\SymbolInfo;
use Qualimetrix\Core\Symbol\SymbolType;

/**
 * Shared logic for namespace-level drill-down: health scores and worst classes.
 *
 * Used by SummaryFormatter and JsonFormatter when --namespace filter is active.
 */
final readonly class HealthScoreDrillDown
{
    private ContributorRanker $contributorRanker;
    private HealthDimensionCatalog $dimensions;

    public function __construct(
        private HealthMetricMetadataProviderInterface $metadataProvider,
        private ComputedMetricDefinitionCatalogInterface $definitionCatalog,
    ) {
        $this->contributorRanker = new ContributorRanker();
        $this->dimensions = new HealthDimensionCatalog();
    }

    /**
     * Builds subtree health scores by weighted-averaging health from all
     * sub-namespaces matching the given prefix.
     *
     * @return array<string, HealthScore> Empty array if no matching namespaces found.
     */
    public function buildSubtreeHealthScores(MetricRepositoryInterface $metrics, string $namespace): array
    {
        $allDimensions = HealthDimension::all();
        [$weightedSums, $dimensionWeights] = $this->collectSubtreeWeights($metrics, $namespace, $allDimensions);

        if ($weightedSums === []) {
            return [];
        }

        // Build HealthScore objects from weighted averages
        $healthScores = [];

        foreach ($allDimensions as $dim) {
            $dimension = $dim->value;
            if (!isset($weightedSums[$dimension], $dimensionWeights[$dimension])) {
                continue;
            }

            $avg = $weightedSums[$dimension] / $dimensionWeights[$dimension];
            [$warnThreshold, $errThreshold] = $this->thresholds($dim);
            $dimensionName = $dim->shortName();

            $inputs = $this->classInputs($dimension);
            $contributors = $inputs === []
                ? []
                : $this->contributorRanker->rank(
                    $this->contributorCandidates($metrics, $this->filterClassesByNamespace($metrics, $namespace), $inputs),
                    $inputs[0]['direction'],
                );

            $healthScores[$dimensionName] = new HealthScore(
                name: $dimensionName,
                score: $avg,
                label: $this->scoreLabel($avg, $warnThreshold, $errThreshold),
                warningThreshold: $warnThreshold,
                errorThreshold: $errThreshold,
                worstContributors: $contributors,
            );
        }

        return $healthScores;
    }

    /**
     * @param list<HealthDimension> $dimensions
     *
     * @return array{array<string, float>, array<string, int>}
     */
    private function collectSubtreeWeights(MetricRepositoryInterface $metrics, string $namespace, array $dimensions): array
    {
        $weightedSums = [];
        $dimensionWeights = [];

        foreach ($metrics->all(SymbolType::Namespace_) as $namespaceInfo) {
            $name = $namespaceInfo->symbolPath->namespace ?? $namespaceInfo->symbolPath->toCanonical();
            if (!$this->matchesNamespace($name, $namespace)) {
                continue;
            }

            $namespaceMetrics = $metrics->get($namespaceInfo->symbolPath);
            $classCount = max(1, (int) ($namespaceMetrics->get($this->dimensions->classCountMetric()) ?? 1));
            $this->accumulateDimensionWeights($namespaceMetrics, $dimensions, $classCount, $weightedSums, $dimensionWeights);
        }

        return [$weightedSums, $dimensionWeights];
    }

    /**
     * @param list<HealthDimension> $dimensions
     * @param array<string, float> $weightedSums
     * @param array<string, int> $dimensionWeights
     */
    private function accumulateDimensionWeights(
        MetricBag $metrics,
        array $dimensions,
        int $classCount,
        array &$weightedSums,
        array &$dimensionWeights,
    ): void {
        foreach ($dimensions as $dimension) {
            $value = $metrics->get($dimension->value);
            if ($value === null) {
                continue;
            }

            $key = $dimension->value;
            $weightedSums[$key] = ($weightedSums[$key] ?? 0.0) + (float) $value * $classCount;
            $dimensionWeights[$key] = ($dimensionWeights[$key] ?? 0) + $classCount;
        }
    }

    /**
     * Builds health scores for a single class from its metrics.
     *
     * @return array<string, HealthScore> Empty array if class not found.
     */
    public function buildClassHealthScores(MetricRepositoryInterface $metrics, string $classFqn): array
    {
        // Find the class in the metrics repository
        $classPath = null;
        foreach ($metrics->all(SymbolType::Class_) as $symbolInfo) {
            $ns = $symbolInfo->symbolPath->namespace ?? '';
            $type = $symbolInfo->symbolPath->type ?? '';
            $fqcn = $ns !== '' ? $ns . '\\' . $type : $type;

            if ($fqcn === $classFqn) {
                $classPath = $symbolInfo->symbolPath;
                break;
            }
        }

        if ($classPath === null) {
            return [];
        }

        $classMetrics = $metrics->get($classPath);
        $healthScores = [];

        foreach (HealthDimension::all() as $dim) {
            $score = $classMetrics->get($dim->value);

            if ($score === null) {
                continue;
            }

            $scoreValue = (float) $score;
            [$warnThreshold, $errThreshold] = $this->thresholds($dim);
            $dimensionName = $dim->shortName();

            $healthScores[$dimensionName] = new HealthScore(
                name: $dimensionName,
                score: $scoreValue,
                label: $this->scoreLabel($scoreValue, $warnThreshold, $errThreshold),
                warningThreshold: $warnThreshold,
                errorThreshold: $errThreshold,
            );
        }

        return $healthScores;
    }

    /**
     * @return Generator<SymbolInfo>
     */
    private function filterClassesByNamespace(MetricRepositoryInterface $metrics, string $namespace): Generator
    {
        foreach ($metrics->all(SymbolType::Class_) as $symbolInfo) {
            $classNs = $symbolInfo->symbolPath->namespace ?? '';

            if ($this->matchesNamespace($classNs, $namespace)) {
                yield $symbolInfo;
            }
        }
    }

    /** @return list<array{classKey: string, direction: string}> */
    private function classInputs(string $dimension): array
    {
        return array_map(static fn(array $input): array => [
            'classKey' => $input['altKey'] ?? $input['key'],
            'direction' => $input['direction'],
        ], $this->metadataProvider->metadata()->healthDecomposition[$dimension]['inputs'] ?? []);
    }

    /**
     * @param iterable<SymbolInfo> $classSymbols
     * @param list<array{classKey: string, direction: string}> $inputs
     *
     * @return Generator<array{symbol: SymbolInfo, primaryValue: float|null, contributorMetrics: array<string, int|float>}>
     */
    private function contributorCandidates(
        MetricRepositoryInterface $repository,
        iterable $classSymbols,
        array $inputs,
    ): Generator {
        foreach ($classSymbols as $symbol) {
            $metrics = $repository->get($symbol->symbolPath);
            $selection = $this->dimensions->selectContributorMetrics($inputs, $metrics->get(...));

            yield [
                'symbol' => $symbol,
                'primaryValue' => $selection['primaryValue'],
                'contributorMetrics' => $selection['contributorMetrics'],
            ];
        }
    }

    private function matchesNamespace(string $subject, string $prefix): bool
    {
        if ($subject === $prefix) {
            return true;
        }

        return str_starts_with($subject, $prefix . '\\');
    }

    /** @return array{float, float} */
    private function thresholds(HealthDimension $dimension): array
    {
        $definition = $this->definitionCatalog->find($dimension->value);

        return [
            $definition->warningThreshold ?? ($dimension === HealthDimension::Typing ? 80.0 : 50.0),
            $definition->errorThreshold ?? match ($dimension) {
                HealthDimension::Typing => 50.0,
                HealthDimension::Overall => 30.0,
                default => 25.0,
            },
        ];
    }

    private function scoreLabel(float $score, float $warningThreshold, float $errorThreshold): string
    {
        $range = 100 - $warningThreshold;
        if ($score > $warningThreshold + $range * 0.6) {
            return 'Excellent';
        }
        if ($score > $warningThreshold + $range * 0.3) {
            return 'Good';
        }
        if ($score > $warningThreshold) {
            return 'Fair';
        }
        if ($score > $errorThreshold) {
            return 'Poor';
        }

        return 'Critical';
    }
}
