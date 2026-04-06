<?php

declare(strict_types=1);

namespace Qualimetrix\Reporting\Health;

use Generator;
use Qualimetrix\Core\ComputedMetric\ComputedMetricDefaults;
use Qualimetrix\Core\ComputedMetric\HealthDimension;
use Qualimetrix\Core\Metric\AggregationStrategy;
use Qualimetrix\Core\Metric\MetricName;
use Qualimetrix\Core\Metric\MetricRepositoryInterface;
use Qualimetrix\Core\Symbol\SymbolInfo;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Core\Symbol\SymbolType;
use Qualimetrix\Core\Violation\Violation;

/**
 * Shared logic for namespace-level drill-down: health scores and worst classes.
 *
 * Used by SummaryFormatter and JsonFormatter when --namespace filter is active.
 */
final readonly class NamespaceDrillDown
{
    private HealthReasonBuilder $reasonBuilder;
    private ContributorRanker $contributorRanker;

    public function __construct(
        private MetricHintProvider $hintProvider,
    ) {
        $this->reasonBuilder = new HealthReasonBuilder($this->hintProvider);
        $this->contributorRanker = new ContributorRanker($this->hintProvider);
    }

    /**
     * Builds subtree health scores by weighted-averaging health from all
     * sub-namespaces matching the given prefix.
     *
     * @return array<string, HealthScore> Empty array if no matching namespaces found.
     */
    public function buildSubtreeHealthScores(MetricRepositoryInterface $metrics, string $namespace): array
    {
        $defaults = ComputedMetricDefaults::getDefaults();
        $allDimensions = HealthDimension::all();

        // Collect health scores from all matching namespaces, weighted by class count
        $weightedSums = [];
        $dimensionWeights = [];

        foreach ($metrics->all(SymbolType::Namespace_) as $nsInfo) {
            $nsName = $nsInfo->symbolPath->namespace ?? $nsInfo->symbolPath->toCanonical();

            if (!$this->matchesNamespace($nsName, $namespace)) {
                continue;
            }

            $nsMetrics = $metrics->get($nsInfo->symbolPath);
            $classCount = (int) ($nsMetrics->get(MetricName::agg(MetricName::SIZE_CLASS_COUNT, AggregationStrategy::Sum)) ?? 1);
            if ($classCount < 1) {
                $classCount = 1;
            }

            foreach ($allDimensions as $dim) {
                $value = $nsMetrics->get($dim->value);
                if ($value === null) {
                    continue;
                }

                $key = $dim->value;
                $weightedSums[$key] = ($weightedSums[$key] ?? 0.0) + (float) $value * $classCount;
                $dimensionWeights[$key] = ($dimensionWeights[$key] ?? 0) + $classCount;
            }
        }

        if ($weightedSums === []) {
            return [];
        }

        // Build HealthScore objects from weighted averages
        $healthScores = [];

        foreach ($allDimensions as $dim) {
            $dimension = $dim->value;
            if (!isset($weightedSums[$dimension])) {
                continue;
            }

            $avg = $weightedSums[$dimension] / $dimensionWeights[$dimension];
            $definition = $defaults[$dimension] ?? null;
            if ($definition === null) {
                continue;
            }

            $warnThreshold = $definition->warningThreshold ?? 50.0;
            $errThreshold = $definition->errorThreshold ?? 25.0;
            $dimensionName = $dim->shortName();

            $contributors = $this->contributorRanker->rank(
                $dimension,
                $metrics,
                $this->filterClassesByNamespace($metrics, $namespace),
            );

            $healthScores[$dimensionName] = new HealthScore(
                name: $dimensionName,
                score: $avg,
                label: $this->hintProvider->getScoreLabel($avg, $warnThreshold, $errThreshold),
                warningThreshold: $warnThreshold,
                errorThreshold: $errThreshold,
                worstContributors: $contributors,
            );
        }

        return $healthScores;
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
        $defaults = ComputedMetricDefaults::getDefaults();
        $overallDef = $defaults[HealthDimension::Overall->value];
        $warnThreshold = $overallDef->warningThreshold ?? 50.0;
        $errThreshold = $overallDef->errorThreshold ?? 30.0;

        // Pre-compute violation counts per class (normalize method-level violations to class)
        $violationCounts = [];
        foreach ($violations as $violation) {
            if ($violation->symbolPath->type === null) {
                continue; // Skip namespace/file-level violations
            }
            $classPath = SymbolPath::forClass(
                $violation->symbolPath->namespace ?? '',
                $violation->symbolPath->type,
            );
            $key = $classPath->toCanonical();
            $violationCounts[$key] = ($violationCounts[$key] ?? 0) + 1;
        }

        /** @var list<WorstOffender> $offenders */
        $offenders = [];

        foreach ($metrics->all(SymbolType::Class_) as $symbolInfo) {
            $classNs = $symbolInfo->symbolPath->namespace ?? '';

            if (!$this->matchesNamespace($classNs, $namespace)) {
                continue;
            }

            $classMetrics = $metrics->get($symbolInfo->symbolPath);
            $healthOverall = $classMetrics->get(HealthDimension::Overall->value);

            if ($healthOverall === null) {
                continue;
            }

            $scoreValue = (float) $healthOverall;
            $label = $this->hintProvider->getScoreLabel($scoreValue, $warnThreshold, $errThreshold);

            $dimensionScores = [];
            foreach (HealthDimension::subDimensions() as $dim) {
                $value = $classMetrics->get($dim->value);
                if ($value !== null) {
                    $dimensionScores[$dim->shortName()] = (float) $value;
                }
            }

            $reason = $this->reasonBuilder->buildReason($dimensionScores);

            $notableMetrics = [];
            if ($includeNotableMetrics) {
                foreach ([MetricName::STRUCTURE_METHOD_COUNT, MetricName::STRUCTURE_PROPERTY_COUNT, MetricName::COUPLING_CBO, MetricName::agg(MetricName::COMPLEXITY_CCN, AggregationStrategy::Average), MetricName::COHESION_TCC, MetricName::STRUCTURE_WMC, MetricName::agg(MetricName::MAINTAINABILITY_MI, AggregationStrategy::Average), MetricName::SIZE_LOC] as $key) {
                    $value = $classMetrics->get($key);
                    if ($value !== null) {
                        $notableMetrics[$key] = $value;
                    }
                }
            }

            $canonical = $symbolInfo->symbolPath->toCanonical();
            $violationCount = $violationCounts[$canonical] ?? 0;

            $density = WorstOffender::computeViolationDensity(
                $violationCount,
                $classMetrics,
                \Qualimetrix\Core\Metric\MetricName::SIZE_CLASS_LOC,
            );

            $offenders[] = new WorstOffender(
                symbolPath: $symbolInfo->symbolPath,
                file: $symbolInfo->file,
                healthOverall: $scoreValue,
                label: $label,
                reason: $reason,
                violationCount: $violationCount,
                classCount: 0,
                metrics: $notableMetrics,
                healthScores: $dimensionScores,
                violationDensity: $density,
            );
        }

        usort($offenders, static fn(WorstOffender $a, WorstOffender $b): int => ($a->healthOverall <=> $b->healthOverall) !== 0 ? ($a->healthOverall <=> $b->healthOverall)
                : ($a->symbolPath->toCanonical() <=> $b->symbolPath->toCanonical()));

        return $offenders;
    }

    /**
     * Builds health scores for a single class from its metrics.
     *
     * @return array<string, HealthScore> Empty array if class not found.
     */
    public function buildClassHealthScores(MetricRepositoryInterface $metrics, string $classFqn): array
    {
        $defaults = ComputedMetricDefaults::getDefaults();
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
            $definition = $defaults[$dim->value];
            $warnThreshold = $definition->warningThreshold ?? 50.0;
            $errThreshold = $definition->errorThreshold ?? 25.0;
            $dimensionName = $dim->shortName();

            $healthScores[$dimensionName] = new HealthScore(
                name: $dimensionName,
                score: $scoreValue,
                label: $this->hintProvider->getScoreLabel($scoreValue, $warnThreshold, $errThreshold),
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

    private function matchesNamespace(string $subject, string $prefix): bool
    {
        if ($subject === $prefix) {
            return true;
        }

        return str_starts_with($subject, $prefix . '\\');
    }
}
