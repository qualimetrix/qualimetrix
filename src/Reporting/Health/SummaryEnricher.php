<?php

declare(strict_types=1);

namespace Qualimetrix\Reporting\Health;

use LogicException;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Contract\Summary\HealthSummary;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Contract\Summary\HealthSummaryBuilder;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\AggregationStrategy;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricName;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\NamespaceTree;
use Qualimetrix\Analysis\Evidence\Prioritization\Debt\DebtCalculator;
use Qualimetrix\Analysis\Evidence\Prioritization\Impact\ImpactCalculator;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Reporting\Report;

/**
 * Assembles report-level debt and impact data with Health-owned summaries.
 */
final readonly class SummaryEnricher
{
    public function __construct(
        private DebtCalculator $debtCalculator,
        private ImpactCalculator $impactCalculator,
        private HealthSummaryBuilder $healthSummaryBuilder,
    ) {}

    public function enrich(Report $report): Report
    {
        if ($report->metrics === null) {
            return $report;
        }

        $tree = $report->namespaceTree ?? new NamespaceTree($report->metrics->getNamespaces());
        $health = $this->healthSummaryBuilder->build($report->metrics, $tree, $report->findings);

        return $this->enrichWithHealth($report, $tree, $health);
    }

    private function enrichWithHealth(Report $report, NamespaceTree $tree, HealthSummary $health): Report
    {
        $metrics = $report->metrics ?? throw new LogicException('Health enrichment requires measured metrics.');
        $debtSummary = $this->debtCalculator->calculate($report->findings);
        $projectMetrics = $metrics->get(SymbolPath::forProject());
        $totalLoc = $projectMetrics->get(MetricName::agg(MetricName::SIZE_LOC, AggregationStrategy::Sum));
        $debtPer1kLoc = ($totalLoc !== null && $totalLoc > 0)
            ? round($debtSummary->totalMinutes / ((float) $totalLoc / 1000), 1)
            : null;
        $topIssues = $this->impactCalculator->computeTopIssues($report->findings, $metrics, $tree);

        return new Report(
            findings: $report->findings,
            filesAnalyzed: $report->filesAnalyzed,
            filesSkipped: $report->filesSkipped,
            duration: $report->duration,
            errorCount: $report->errorCount,
            warningCount: $report->warningCount,
            metrics: $report->metrics,
            healthScores: $health->healthScores,
            worstNamespaces: $health->worstNamespaces,
            worstClasses: $health->worstClasses,
            techDebtMinutes: $debtSummary->totalMinutes,
            debtPer1kLoc: $debtPer1kLoc,
            topIssues: $topIssues,
            namespaceTree: $tree,
            infoCount: $report->infoCount,
            coverage: $report->coverage,
            suppressionComposition: $report->suppressionComposition,
        );
    }
}
