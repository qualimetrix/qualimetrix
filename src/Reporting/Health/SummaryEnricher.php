<?php

declare(strict_types=1);

namespace Qualimetrix\Reporting\Health;

use Qualimetrix\Core\ComputedMetric\ComputedMetricDefaults;
use Qualimetrix\Core\ComputedMetric\ComputedMetricDefinitionHolder;
use Qualimetrix\Core\Metric\MetricName;
use Qualimetrix\Core\Namespace_\NamespaceTree;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Core\Symbol\SymbolType;
use Qualimetrix\Core\Violation\Violation;
use Qualimetrix\Reporting\Debt\DebtCalculator;
use Qualimetrix\Reporting\Impact\ImpactCalculator;
use Qualimetrix\Reporting\Report;

/**
 * Enriches a Report with health scores, worst offenders, and tech debt.
 */
final readonly class SummaryEnricher
{
    private const int DEFAULT_TOP_NAMESPACES = 10;
    private const int DEFAULT_TOP_CLASSES = 10;

    private HealthReasonBuilder $reasonBuilder;
    private ContributorRanker $contributorRanker;

    public function __construct(
        private DebtCalculator $debtCalculator,
        private MetricHintProvider $hintProvider,
        private ImpactCalculator $impactCalculator,
    ) {
        $this->reasonBuilder = new HealthReasonBuilder($this->hintProvider);
        $this->contributorRanker = new ContributorRanker($this->hintProvider);
    }

    public function enrich(Report $report): Report
    {
        if ($report->metrics === null) {
            return $report;
        }

        $tree = $report->namespaceTree ?? new NamespaceTree($report->metrics->getNamespaces());

        $healthScores = $this->buildHealthScores($report);
        $worstNamespaces = $this->buildWorstOffenders($report, SymbolType::Namespace_, self::DEFAULT_TOP_NAMESPACES, $tree);
        $worstClasses = $this->buildWorstOffenders($report, SymbolType::Class_, self::DEFAULT_TOP_CLASSES, $tree);
        $debtSummary = $this->debtCalculator->calculate($report->violations);

        // Compute debt density (minutes per 1K LOC)
        $projectMetrics = $report->metrics->get(SymbolPath::forProject());
        $totalLoc = $projectMetrics->get(MetricName::SIZE_LOC . '.sum');
        $debtPer1kLoc = ($totalLoc !== null && $totalLoc > 0)
            ? round($debtSummary->totalMinutes / ((float) $totalLoc / 1000), 1)
            : null;

        $topIssues = $this->impactCalculator->computeTopIssues($report->violations, $report->metrics, $tree);

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
            topIssues: $topIssues,
            namespaceTree: $tree,
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

            $decomposition = $this->buildDecomposition($dimension, $projectMetrics);
            $contributors = $this->contributorRanker->rank(
                $dimension,
                $report->metrics,
                $report->metrics->all(SymbolType::Class_),
            );

            $dimensionName = str_replace('health.', '', $dimension);
            $healthScores[$dimensionName] = new HealthScore(
                name: $dimensionName,
                score: $scoreValue,
                label: $this->hintProvider->getScoreLabel($scoreValue, $warnThreshold, $errThreshold),
                warningThreshold: $warnThreshold,
                errorThreshold: $errThreshold,
                decomposition: $decomposition,
                worstContributors: $contributors,
            );
        }

        // Show typing dimension with "0 classes analyzed" when other dimensions exist but typing doesn't,
        // unless typing was explicitly excluded via --exclude-health
        if ($healthScores !== [] && !isset($healthScores['typing']) && !$this->isDefinitionExcluded('health.typing')) {
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
     * Builds decomposition items for a health dimension.
     *
     * Always returns the contributing metrics regardless of score value,
     * so that JSON consumers can inspect what feeds into each dimension.
     *
     * @return list<DecompositionItem>
     */
    private function buildDecomposition(
        string $dimension,
        \Qualimetrix\Core\Metric\MetricBag $projectMetrics,
    ): array {
        // Typing dimension needs special handling: compute percentages from raw sums
        if ($dimension === 'health.typing') {
            return $this->buildTypingDecomposition($projectMetrics);
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
     * @return list<DecompositionItem>
     */
    private function buildTypingDecomposition(\Qualimetrix\Core\Metric\MetricBag $metrics): array
    {
        $components = [
            ['label' => 'Parameter types', 'typed' => 'typeCoverage.paramTyped.sum', 'total' => 'typeCoverage.paramTotal.sum'],
            ['label' => 'Return types', 'typed' => 'typeCoverage.returnTyped.sum', 'total' => 'typeCoverage.returnTotal.sum'],
            ['label' => 'Property types', 'typed' => 'typeCoverage.propertyTyped.sum', 'total' => 'typeCoverage.propertyTotal.sum'],
        ];

        $items = [];

        foreach ($components as $component) {
            $typed = $metrics->get($component['typed']);
            $total = $metrics->get($component['total']);

            if ($total === null || (int) $total === 0) {
                continue;
            }

            $pct = round((float) $typed / (float) $total * 100, 1);

            $items[] = new DecompositionItem(
                metricKey: $component['typed'],
                humanName: $component['label'],
                value: $pct,
                goodValue: '100%',
                direction: 'higher_is_better',
                explanation: \sprintf('%d of %d typed (%.1f%%)', (int) $typed, (int) $total, $pct),
            );
        }

        return $items;
    }

    /**
     * @return list<WorstOffender>
     */
    private function buildWorstOffenders(Report $report, SymbolType $symbolType, int $limit, NamespaceTree $tree): array
    {
        \assert($report->metrics !== null);

        $defaults = ComputedMetricDefaults::getDefaults();
        $overallDef = $defaults['health.overall'];
        $warnThreshold = $overallDef->warningThreshold ?? 50.0;

        /** @var list<array{score: float, info: \Qualimetrix\Core\Symbol\SymbolInfo}> $candidates */
        $candidates = [];

        foreach ($report->metrics->all($symbolType) as $symbolInfo) {
            $metrics = $report->metrics->get($symbolInfo->symbolPath);
            $healthOverall = $metrics->get('health.overall');

            if ($healthOverall === null) {
                continue;
            }

            $scoreValue = (float) $healthOverall;

            // Skip namespaces with no direct classes (e.g., root namespace containers like "PHPUnit")
            if ($symbolType === SymbolType::Namespace_) {
                $classCountInNs = (int) ($metrics->get('classCount.sum') ?? 0);

                if ($classCountInNs === 0) {
                    continue;
                }
            }

            $candidates[] = ['score' => $scoreValue, 'info' => $symbolInfo];
        }

        // Sort by score ascending (worst first), with stable secondary sort by canonical path
        usort($candidates, static fn(array $a, array $b): int => $a['score'] <=> $b['score']
                ?: $a['info']->symbolPath->toCanonical() <=> $b['info']->symbolPath->toCanonical());

        $violationCounts = $this->countViolationsPerSymbol($report->violations, $symbolType, $tree);

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
            $reason = $this->reasonBuilder->buildReason($perDimensionScores);

            $symbolCanonical = $symbolInfo->symbolPath->toCanonical();
            $violationCount = $violationCounts[$symbolCanonical] ?? 0;
            $classCount = $symbolType === SymbolType::Namespace_
                ? (int) ($metrics->get('classCount.sum') ?? 0)
                : 0;

            $file = $symbolType === SymbolType::Class_ ? $symbolInfo->file : null;

            $notableMetrics = $this->getNotableMetrics($metrics, $symbolType);

            $density = WorstOffender::computeViolationDensity(
                $violationCount,
                $metrics,
                $symbolType === SymbolType::Namespace_ ? 'loc.sum' : MetricName::SIZE_CLASS_LOC,
            );

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
                violationDensity: $density,
            );
        }

        return $offenders;
    }

    /**
     * @return array<string, float>
     */
    private function getPerDimensionScores(\Qualimetrix\Core\Metric\MetricBag $metrics): array
    {
        $dimensions = ['complexity', 'cohesion', 'coupling', 'typing', 'maintainability', 'overall'];
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
     * @param list<Violation> $violations
     *
     * @return array<string, int>
     */
    private function countViolationsPerSymbol(array $violations, SymbolType $symbolType, NamespaceTree $tree): array
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
                // Count violations by namespace, walking up the hierarchy via NamespaceTree
                $ns = $violation->symbolPath->namespace;

                if ($ns !== null && $ns !== '') {
                    $nsPath = SymbolPath::forNamespace($ns);
                    $key = $nsPath->toCanonical();
                    $counts[$key] = ($counts[$key] ?? 0) + 1;

                    foreach ($tree->getAncestors($ns) as $ancestor) {
                        $ancestorPath = SymbolPath::forNamespace($ancestor);
                        $key = $ancestorPath->toCanonical();
                        $counts[$key] = ($counts[$key] ?? 0) + 1;
                    }
                }
            }
        }

        return $counts;
    }

    /**
     * @return array<string, int|float>
     */
    private function getNotableMetrics(\Qualimetrix\Core\Metric\MetricBag $metrics, SymbolType $symbolType): array
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

    /**
     * Checks if a computed metric definition was excluded via --exclude-health.
     *
     * Returns true only when definitions are loaded AND the named metric is not among them.
     * When no definitions are loaded (e.g., in tests), returns false (not excluded).
     */
    private function isDefinitionExcluded(string $name): bool
    {
        $definitions = ComputedMetricDefinitionHolder::getDefinitions();

        if ($definitions === []) {
            return false;
        }

        foreach ($definitions as $def) {
            if ($def->name === $name) {
                return false;
            }
        }

        return true;
    }
}
