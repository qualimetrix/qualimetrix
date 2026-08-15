<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Contract\DrillDown;

use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Definition\ComputedMetricDefinitionCatalogInterface;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Definition\HealthDimension;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Contract\Offender\WorstOffender;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Metadata\HealthDimensionCatalog;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Offender\WorstOffenderBuilder;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricRepositoryInterface;
use Qualimetrix\Analysis\Finding\Contract\Violation;
use Qualimetrix\Core\Symbol\SymbolInfo;
use Qualimetrix\Core\Symbol\SymbolType;

/**
 * Shared logic for namespace-level drill-down: health scores and worst classes.
 *
 * Used by SummaryFormatter and JsonFormatter when --namespace filter is active.
 */
final readonly class WorstClassDrillDown
{
    private HealthDimensionCatalog $dimensions;

    public function __construct(
        private ComputedMetricDefinitionCatalogInterface $definitionCatalog,
        private WorstOffenderBuilder $offenderBuilder = new WorstOffenderBuilder(),
    ) {
        $this->dimensions = new HealthDimensionCatalog();
    }

    /**
     * Builds worst class offenders within a namespace subtree.
     *
     * @param list<Violation> $violations All violations (for counting per class)
     *
     * @return list<WorstOffender> Sorted by health score ascending (worst first).
     */
    public function buildWorstClasses(
        MetricRepositoryInterface $metrics,
        string $namespace,
        array $violations,
        bool $includeNotableMetrics = false,
    ): array {
        [$warnThreshold, $errThreshold] = $this->overallThresholds();
        $notableMetricNames = $includeNotableMetrics
            ? $this->dimensions->notableClassMetrics()
            : [];

        $offenders = $this->offenderBuilder->buildWorstClasses(
            $this->snapshots($metrics, $notableMetricNames),
            $namespace,
            $violations,
            $warnThreshold,
            $errThreshold,
        );

        usort($offenders, static fn(WorstOffender $a, WorstOffender $b): int => ($a->healthOverall <=> $b->healthOverall) !== 0 ? ($a->healthOverall <=> $b->healthOverall)
                : ($a->symbolPath->toCanonical() <=> $b->symbolPath->toCanonical()));

        return $offenders;
    }

    /**
     * @param list<string> $notableMetricNames
     *
     * @return iterable<array{symbol: SymbolInfo, overall: float|null, dimensionScores: array<string, float>, loc: int|float|null, notableMetrics: array<string, int|float>}>
     */
    private function snapshots(MetricRepositoryInterface $repository, array $notableMetricNames): iterable
    {
        foreach ($repository->all(SymbolType::Class_) as $symbol) {
            $metrics = $repository->get($symbol->symbolPath);
            $overall = $metrics->get($this->dimensions->overallMetric());
            yield [
                'symbol' => $symbol,
                'overall' => $overall === null ? null : (float) $overall,
                'dimensionScores' => $this->dimensionScores($metrics->get(...)),
                'loc' => $metrics->get($this->dimensions->classLocMetric()),
                'notableMetrics' => $this->selectedMetrics($metrics->get(...), $notableMetricNames),
            ];
        }
    }

    /**
     * @param callable(string): (int|float|null) $readMetric
     *
     * @return array<string, float>
     */
    private function dimensionScores(callable $readMetric): array
    {
        $scores = [];
        foreach ($this->dimensions->scoreDimensions() as $shortName => $metricName) {
            $value = $readMetric($metricName);
            if ($value !== null) {
                $scores[$shortName] = (float) $value;
            }
        }

        return $scores;
    }

    /**
     * @param callable(string): (int|float|null) $readMetric
     * @param list<string> $metricNames
     *
     * @return array<string, int|float>
     */
    private function selectedMetrics(callable $readMetric, array $metricNames): array
    {
        $selected = [];
        foreach ($metricNames as $metricName) {
            $value = $readMetric($metricName);
            if ($value !== null) {
                $selected[$metricName] = $value;
            }
        }

        return $selected;
    }

    /** @return array{float, float} */
    private function overallThresholds(): array
    {
        $definition = $this->definitionCatalog->find(HealthDimension::Overall->value);

        return [
            $definition->warningThreshold ?? 50.0,
            $definition->errorThreshold ?? 30.0,
        ];
    }
}
