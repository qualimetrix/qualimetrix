<?php

declare(strict_types=1);

namespace AiMessDetector\Reporting;

use AiMessDetector\Core\ComputedMetric\ComputedMetricDefaults;
use AiMessDetector\Core\Metric\MetricName;
use AiMessDetector\Core\Symbol\SymbolPath;
use AiMessDetector\Core\Symbol\SymbolType;
use AiMessDetector\Core\Violation\Violation;
use AiMessDetector\Reporting\Debt\DebtCalculator;

/**
 * Enriches a Report with health scores, worst offenders, and tech debt.
 */
final readonly class SummaryEnricher
{
    private const int DEFAULT_TOP_NAMESPACES = 10;
    private const int DEFAULT_TOP_CLASSES = 10;

    public function __construct(
        private DebtCalculator $debtCalculator,
        private MetricHintProvider $hintProvider,
    ) {}

    public function enrich(Report $report, bool $partialAnalysis = false): Report
    {
        if ($partialAnalysis || $report->metrics === null) {
            return $report;
        }

        $healthScores = $this->buildHealthScores($report);
        $worstNamespaces = $this->buildWorstOffenders($report, SymbolType::Namespace_, self::DEFAULT_TOP_NAMESPACES);
        $worstClasses = $this->buildWorstOffenders($report, SymbolType::Class_, self::DEFAULT_TOP_CLASSES);
        $debtSummary = $this->debtCalculator->calculate($report->violations);

        // Compute debt density (minutes per 1K LOC)
        $projectMetrics = $report->metrics->get(SymbolPath::forProject());
        $totalLoc = $projectMetrics->get(MetricName::SIZE_LOC . '.sum');
        $debtPer1kLoc = ($totalLoc !== null && $totalLoc > 0)
            ? round($debtSummary->totalMinutes / ((float) $totalLoc / 1000), 1)
            : null;

        return new Report(
            violations: $report->violations,
            filesAnalyzed: $report->filesAnalyzed,
            filesSkipped: $report->filesSkipped,
            duration: $report->duration,
            errorCount: $report->errorCount,
            warningCount: $report->warningCount,
            metrics: $report->metrics,
            healthScores: $healthScores,
            worstNamespaces: $worstNamespaces,
            worstClasses: $worstClasses,
            techDebtMinutes: $debtSummary->totalMinutes,
            debtPer1kLoc: $debtPer1kLoc,
        );
    }

    /**
     * @return array<string, HealthScore>
     */
    private function buildHealthScores(Report $report): array
    {
        \assert($report->metrics !== null);

        $projectMetrics = $report->metrics->get(SymbolPath::forProject());
        $defaults = ComputedMetricDefaults::getDefaults();
        $healthScores = [];

        $dimensions = ['health.complexity', 'health.cohesion', 'health.coupling', 'health.typing', 'health.maintainability', 'health.overall'];

        foreach ($dimensions as $dimension) {
            $score = $projectMetrics->get($dimension);

            if ($score === null) {
                continue;
            }

            $scoreValue = (float) $score;
            $definition = $defaults[$dimension];
            $warnThreshold = $definition->warningThreshold ?? 50.0;
            $errThreshold = $definition->errorThreshold ?? 25.0;

            $decomposition = $this->buildDecomposition($dimension, $projectMetrics, $scoreValue, $warnThreshold);

            $dimensionName = str_replace('health.', '', $dimension);
            $healthScores[$dimensionName] = new HealthScore(
                name: $dimensionName,
                score: $scoreValue,
                label: $this->hintProvider->getScoreLabel($scoreValue, $warnThreshold, $errThreshold),
                warningThreshold: $warnThreshold,
                errorThreshold: $errThreshold,
                decomposition: $decomposition,
            );
        }

        // Show typing dimension with "0 classes analyzed" when other dimensions exist but typing doesn't
        if ($healthScores !== [] && !isset($healthScores['typing'])) {
            $typingDef = $defaults['health.typing'];
            $healthScores['typing'] = new HealthScore(
                name: 'typing',
                score: null,
                label: '0 classes analyzed',
                warningThreshold: $typingDef->warningThreshold ?? 50.0,
                errorThreshold: $typingDef->errorThreshold ?? 25.0,
            );
        }

        return $healthScores;
    }

    /**
     * @return list<DecompositionItem>
     */
    private function buildDecomposition(
        string $dimension,
        \AiMessDetector\Core\Metric\MetricBag $projectMetrics,
        float $score,
        float $warnThreshold,
    ): array {
        // Only decompose when score needs attention
        if ($score > $warnThreshold) {
            return [];
        }

        $metricKeys = $this->hintProvider->getDecomposition($dimension);
        $items = [];

        foreach ($metricKeys as $metricKey) {
            $value = $projectMetrics->get($metricKey);

            if ($value === null) {
                continue;
            }

            $floatValue = (float) $value;
            $label = $this->hintProvider->getLabel($metricKey) ?? $metricKey;
            $goodValue = $this->hintProvider->getGoodValue($metricKey) ?? '';
            $direction = $this->hintProvider->getDirection($metricKey) ?? 'lower_is_better';
            $explanation = $this->hintProvider->getExplanation($metricKey, $floatValue);

            $items[] = new DecompositionItem(
                metricKey: $metricKey,
                humanName: $label,
                value: $floatValue,
                goodValue: $goodValue,
                direction: $direction,
                explanation: $explanation,
            );
        }

        return $items;
    }

    /**
     * @return list<WorstOffender>
     */
    private function buildWorstOffenders(Report $report, SymbolType $symbolType, int $limit): array
    {
        \assert($report->metrics !== null);

        $defaults = ComputedMetricDefaults::getDefaults();
        $overallDef = $defaults['health.overall'];
        $warnThreshold = $overallDef->warningThreshold ?? 50.0;

        /** @var list<array{score: float, info: \AiMessDetector\Core\Symbol\SymbolInfo}> $candidates */
        $candidates = [];

        foreach ($report->metrics->all($symbolType) as $symbolInfo) {
            $metrics = $report->metrics->get($symbolInfo->symbolPath);
            $healthOverall = $metrics->get('health.overall');

            if ($healthOverall === null) {
                continue;
            }

            $scoreValue = (float) $healthOverall;

            // Only include symbols below the warning threshold
            if ($scoreValue > $warnThreshold) {
                continue;
            }

            $candidates[] = ['score' => $scoreValue, 'info' => $symbolInfo];
        }

        // Sort by score ascending (worst first), with stable secondary sort by canonical path
        usort($candidates, static fn(array $a, array $b): int => $a['score'] <=> $b['score']
                ?: $a['info']->symbolPath->toCanonical() <=> $b['info']->symbolPath->toCanonical());

        $violationCounts = $this->countViolationsPerSymbol($report->violations, $symbolType);

        $offenders = [];

        foreach (\array_slice($candidates, 0, $limit) as $candidate) {
            $symbolInfo = $candidate['info'];
            $metrics = $report->metrics->get($symbolInfo->symbolPath);
            $scoreValue = $candidate['score'];

            $label = $this->hintProvider->getScoreLabel(
                $scoreValue,
                $warnThreshold,
                $overallDef->errorThreshold ?? 30.0,
            );

            $perDimensionScores = $this->getPerDimensionScores($metrics);
            $reason = $this->buildReason($perDimensionScores);

            $symbolCanonical = $symbolInfo->symbolPath->toCanonical();
            $violationCount = $violationCounts[$symbolCanonical] ?? 0;
            $classCount = $symbolType === SymbolType::Namespace_
                ? (int) ($metrics->get('classCount.sum') ?? 0)
                : 0;

            $file = $symbolType === SymbolType::Class_ ? $symbolInfo->file : null;

            $notableMetrics = $this->getNotableMetrics($metrics, $symbolType);

            $offenders[] = new WorstOffender(
                symbolPath: $symbolInfo->symbolPath,
                file: $file,
                healthOverall: $scoreValue,
                label: $label,
                reason: $reason,
                violationCount: $violationCount,
                classCount: $classCount,
                metrics: $notableMetrics,
                healthScores: $perDimensionScores,
            );
        }

        return $offenders;
    }

    /**
     * @return array<string, float>
     */
    private function getPerDimensionScores(\AiMessDetector\Core\Metric\MetricBag $metrics): array
    {
        $dimensions = ['complexity', 'cohesion', 'coupling', 'typing', 'maintainability'];
        $scores = [];

        foreach ($dimensions as $dim) {
            $value = $metrics->get('health.' . $dim);

            if ($value !== null) {
                $scores[$dim] = (float) $value;
            }
        }

        return $scores;
    }

    /**
     * @param array<string, float> $dimensionScores
     */
    private function buildReason(array $dimensionScores): string
    {
        if ($dimensionScores === []) {
            return '';
        }

        $defaults = ComputedMetricDefaults::getDefaults();
        $ranked = [];

        foreach ($dimensionScores as $dim => $score) {
            $defKey = 'health.' . $dim;
            $warnThreshold = isset($defaults[$defKey]) ? $defaults[$defKey]->warningThreshold : 50.0;
            // How far below the warning threshold (negative = bad)
            $delta = $score - $warnThreshold;
            $ranked[] = ['dim' => $dim, 'delta' => $delta, 'score' => $score];
        }

        // Sort by delta ascending (worst first)
        usort($ranked, static fn(array $a, array $b): int => $a['delta'] <=> $b['delta']);

        $reasons = [];

        foreach (\array_slice($ranked, 0, 2) as $item) {
            if ($item['delta'] > 0) {
                // Above warning threshold — not a problem
                continue;
            }

            $reasons[] = $this->hintProvider->getHealthDimensionLabel($item['dim'], true);
        }

        return implode(', ', $reasons);
    }

    /**
     * @param list<Violation> $violations
     *
     * @return array<string, int>
     */
    private function countViolationsPerSymbol(array $violations, SymbolType $symbolType): array
    {
        $counts = [];

        foreach ($violations as $violation) {
            if ($symbolType === SymbolType::Class_) {
                // Count violations by class
                $classPath = SymbolPath::forClass(
                    $violation->symbolPath->namespace ?? '',
                    $violation->symbolPath->type ?? '',
                );

                if ($violation->symbolPath->type !== null) {
                    $key = $classPath->toCanonical();
                    $counts[$key] = ($counts[$key] ?? 0) + 1;
                }
            } elseif ($symbolType === SymbolType::Namespace_) {
                // Count violations by namespace, walking up the hierarchy
                $ns = $violation->symbolPath->namespace;

                while ($ns !== null && $ns !== '') {
                    $nsPath = SymbolPath::forNamespace($ns);
                    $key = $nsPath->toCanonical();
                    $counts[$key] = ($counts[$key] ?? 0) + 1;
                    $lastSlash = strrpos($ns, '\\');
                    $ns = $lastSlash !== false ? substr($ns, 0, $lastSlash) : null;
                }
            }
        }

        return $counts;
    }

    /**
     * @return array<string, int|float>
     */
    private function getNotableMetrics(\AiMessDetector\Core\Metric\MetricBag $metrics, SymbolType $symbolType): array
    {
        $notable = [];
        $keys = $symbolType === SymbolType::Class_
            ? ['methodCount', 'propertyCount', 'cbo', 'ccn.avg', 'tcc', 'wmc', 'mi.avg', 'loc']
            : ['classCount.sum', 'cbo.avg', 'ccn.avg', 'distance', 'mi.avg'];

        foreach ($keys as $key) {
            $value = $metrics->get($key);

            if ($value !== null) {
                $notable[$key] = $value;
            }
        }

        return $notable;
    }
}
