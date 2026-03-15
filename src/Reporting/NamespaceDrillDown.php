<?php

declare(strict_types=1);

namespace AiMessDetector\Reporting;

use AiMessDetector\Core\ComputedMetric\ComputedMetricDefaults;
use AiMessDetector\Core\Metric\MetricRepositoryInterface;
use AiMessDetector\Core\Symbol\SymbolPath;
use AiMessDetector\Core\Symbol\SymbolType;
use AiMessDetector\Core\Violation\Violation;

/**
 * Shared logic for namespace-level drill-down: health scores and worst classes.
 *
 * Used by SummaryFormatter and JsonFormatter when --namespace filter is active.
 */
final readonly class NamespaceDrillDown
{
    public function __construct(
        private MetricHintProvider $hintProvider,
    ) {}

    /**
     * Builds subtree health scores by weighted-averaging health from all
     * sub-namespaces matching the given prefix.
     *
     * @return array<string, HealthScore> Empty array if no matching namespaces found.
     */
    public function buildSubtreeHealthScores(MetricRepositoryInterface $metrics, string $namespace): array
    {
        $defaults = ComputedMetricDefaults::getDefaults();
        $dimensions = ['health.complexity', 'health.cohesion', 'health.coupling', 'health.typing', 'health.maintainability', 'health.overall'];

        // Collect health scores from all matching namespaces, weighted by class count
        $weightedSums = [];
        $dimensionWeights = [];

        foreach ($metrics->all(SymbolType::Namespace_) as $nsInfo) {
            $nsName = $nsInfo->symbolPath->namespace ?? $nsInfo->symbolPath->toCanonical();

            if (!$this->matchesNamespace($nsName, $namespace)) {
                continue;
            }

            $nsMetrics = $metrics->get($nsInfo->symbolPath);
            $classCount = (int) ($nsMetrics->get('classCount.sum') ?? 1);
            if ($classCount < 1) {
                $classCount = 1;
            }

            foreach ($dimensions as $dim) {
                $value = $nsMetrics->get($dim);
                if ($value === null) {
                    continue;
                }

                $weightedSums[$dim] = ($weightedSums[$dim] ?? 0.0) + (float) $value * $classCount;
                $dimensionWeights[$dim] = ($dimensionWeights[$dim] ?? 0) + $classCount;
            }
        }

        if ($weightedSums === []) {
            return [];
        }

        // Build HealthScore objects from weighted averages
        $healthScores = [];

        foreach ($dimensions as $dimension) {
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
            $dimensionName = str_replace('health.', '', $dimension);

            $healthScores[$dimensionName] = new HealthScore(
                name: $dimensionName,
                score: $avg,
                label: $this->hintProvider->getScoreLabel($avg, $warnThreshold, $errThreshold),
                warningThreshold: $warnThreshold,
                errorThreshold: $errThreshold,
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
        $overallDef = $defaults['health.overall'];
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
            $healthOverall = $classMetrics->get('health.overall');

            if ($healthOverall === null) {
                continue;
            }

            $scoreValue = (float) $healthOverall;
            $label = $this->hintProvider->getScoreLabel($scoreValue, $warnThreshold, $errThreshold);

            $dimensionScores = [];
            foreach (['complexity', 'cohesion', 'coupling', 'typing', 'maintainability'] as $dim) {
                $value = $classMetrics->get('health.' . $dim);
                if ($value !== null) {
                    $dimensionScores[$dim] = (float) $value;
                }
            }

            $reason = $this->buildReason($dimensionScores);

            $notableMetrics = [];
            if ($includeNotableMetrics) {
                foreach (['methodCount', 'propertyCount', 'cbo', 'ccn.avg', 'tcc', 'wmc', 'mi.avg', 'loc'] as $key) {
                    $value = $classMetrics->get($key);
                    if ($value !== null) {
                        $notableMetrics[$key] = $value;
                    }
                }
            }

            $canonical = $symbolInfo->symbolPath->toCanonical();
            $offenders[] = new WorstOffender(
                symbolPath: $symbolInfo->symbolPath,
                file: $symbolInfo->file,
                healthOverall: $scoreValue,
                label: $label,
                reason: $reason,
                violationCount: $violationCounts[$canonical] ?? 0,
                classCount: 0,
                metrics: $notableMetrics,
                healthScores: $dimensionScores,
            );
        }

        usort($offenders, static fn(WorstOffender $a, WorstOffender $b): int => $a->healthOverall <=> $b->healthOverall
                ?: $a->symbolPath->toCanonical() <=> $b->symbolPath->toCanonical());

        return $offenders;
    }

    /**
     * Builds a human-readable reason string from dimension scores.
     *
     * @param array<string, float> $dimensionScores
     */
    public function buildReason(array $dimensionScores): string
    {
        if ($dimensionScores === []) {
            return '';
        }

        $defaults = ComputedMetricDefaults::getDefaults();
        $ranked = [];

        foreach ($dimensionScores as $dim => $score) {
            $defKey = 'health.' . $dim;
            $warnThreshold = isset($defaults[$defKey]) ? ($defaults[$defKey]->warningThreshold ?? 50.0) : 50.0;
            $delta = $score - $warnThreshold;
            $ranked[] = ['dim' => $dim, 'delta' => $delta];
        }

        usort($ranked, static fn(array $a, array $b): int => $a['delta'] <=> $b['delta']);

        $reasons = [];
        foreach (\array_slice($ranked, 0, 2) as $item) {
            if ($item['delta'] >= 0) {
                continue;
            }
            $reasons[] = $this->hintProvider->getHealthDimensionLabel($item['dim'], true);
        }

        return implode(', ', $reasons);
    }

    /**
     * Builds health scores for a single class from its metrics.
     *
     * @return array<string, HealthScore> Empty array if class not found.
     */
    public function buildClassHealthScores(MetricRepositoryInterface $metrics, string $classFqn): array
    {
        $defaults = ComputedMetricDefaults::getDefaults();
        $dimensions = ['health.complexity', 'health.cohesion', 'health.coupling', 'health.typing', 'health.maintainability', 'health.overall'];

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

        foreach ($dimensions as $dimension) {
            $score = $classMetrics->get($dimension);

            if ($score === null) {
                continue;
            }

            $scoreValue = (float) $score;
            $definition = $defaults[$dimension];
            $warnThreshold = $definition->warningThreshold ?? 50.0;
            $errThreshold = $definition->errorThreshold ?? 25.0;
            $dimensionName = str_replace('health.', '', $dimension);

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

    private function matchesNamespace(string $subject, string $prefix): bool
    {
        if ($subject === $prefix) {
            return true;
        }

        return str_starts_with($subject, $prefix . '\\');
    }
}
