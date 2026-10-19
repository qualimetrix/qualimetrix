<?php

declare(strict_types=1);

namespace Qualimetrix\Reporting\Contract\Report;

use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Evaluation\ComputedMetricEvaluationSummary;

use Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Contract\Offender\WorstOffender;

use Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Contract\Score\HealthScore;

use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricRepositoryInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\NamespaceTree;
use Qualimetrix\Analysis\Evidence\Prioritization\Impact\RankedIssue;
use Qualimetrix\Analysis\Finding\Contract\Finding;
use Qualimetrix\Analysis\Finding\Contract\Population\JudgedPopulation;
use Qualimetrix\Analysis\Finding\Contract\Severity;
use Qualimetrix\Reporting\Coverage\ReportCoverage;
use Qualimetrix\Reporting\DrillDown\OutOfScopeFindings;
use Qualimetrix\Reporting\FindingProjection\SuppressionComposition;
use Qualimetrix\Reporting\Health\InheritanceDepthCompleteness;

/**
 * Value Object representing the analysis report.
 *
 * @qmx-threshold size.property-count 26 -- Backed publication facts and computed count or health projections form one report snapshot; the projections do not add independent state.
 * @qmx-threshold design.data-class 10 -- The immutable publication snapshot intentionally exposes data. Computed read-only properties cannot live in a readonly PHP class; the finite WOC floor keeps additional accessor-only growth visible.
 */
final class Report
{
    public int $errorCount { get => $this->countSeverity(Severity::Error); }
    public int $warningCount { get => $this->countSeverity(Severity::Warning); }
    public int $infoCount { get => $this->countSeverity(Severity::Info); }
    /** @var array<string, HealthScore> */
    public array $healthScores { get => $this->health->healthScores; }
    /** @var list<WorstOffender> */
    public array $worstNamespaces { get => $this->health->worstNamespaces; }
    /** @var list<WorstOffender> */
    public array $worstClasses { get => $this->health->worstClasses; }
    public int $techDebtMinutes { get => $this->health->techDebtMinutes; }
    public ?float $debtPer1kLoc { get => $this->health->debtPer1kLoc; }
    /** @var list<RankedIssue> */
    public array $topIssues { get => $this->health->topIssues; }
    public ?NamespaceTree $namespaceTree { get => $this->health->namespaceTree; }
    public readonly JudgedPopulation $population;
    public readonly InheritanceDepthCompleteness $inheritanceDepth;
    /**
     * @param list<Finding> $findings
     * @param ?SuppressionComposition $suppressionComposition What the `suppressed` format
     *                                                        publishes; `null` on every ordinary
     *                                                        `check` run — building it costs the
     *                                                        per-rule ledger's opt-in memory, so it
     *                                                        is populated only when `--show-suppressed`
     *                                                        or `--format=suppressed` asked for it.
     *                                                        Absent from every other formatter's
     *                                                        payload, so its presence never moves
     *                                                        `check`'s own output.
     * @param ?OutOfScopeFindings $outOfScope What a `--namespace`/`--class` selection left out
     *                                        of `$findings`; `null` when no selection is active,
     *                                        so `$findings` is then the whole run.
     * @param ?ReportProjectScope $projectScope Whether the run's paths covered the project;
     *                                          `null` only for a report no run measured
     * @param list<array{message: string, source: list<array<string, mixed>>}> $configurationDiagnostics Warnings about
     *                                                                                                   the accepted configuration,
     *                                                                                                   already published: each
     *                                                                                                   `source` entry is the
     *                                                                                                   refusal envelope's
     *
      * @qmx-threshold code-smell.long-parameter-list 15 -- Sources, published findings and measured coverage form one synchronized publication snapshot; health enrichment replaces one report-owned value.
      * @qmx-threshold code-smell.constructor-overinjection 15 -- Sources, published findings and measured coverage form one synchronized publication snapshot; health enrichment replaces one report-owned value.
     */
    public function __construct(
        public readonly \Qualimetrix\Analysis\Evidence\Measurement\Contract\FileNamespaceIndex $fileNamespaces,
        public readonly array $findings,
        public readonly int $filesAnalyzed,
        public readonly int $filesSkipped,
        public readonly float $duration,
        public readonly ?MetricRepositoryInterface $metrics = null,
        public readonly ?ReportCoverage $coverage = null,
        public readonly ?SuppressionComposition $suppressionComposition = null,
        public readonly ?OutOfScopeFindings $outOfScope = null,
        public readonly ?ReportProjectScope $projectScope = null,
        public readonly array $configurationDiagnostics = [],
        public readonly ComputedMetricEvaluationSummary $computedMetricEvaluation = new ComputedMetricEvaluationSummary(),
        ?JudgedPopulation $population = null,
        public readonly ReportHealth $health = new ReportHealth(),
    ) {
        $this->population = $population ?? JudgedPopulation::empty();
        $this->inheritanceDepth = InheritanceDepthCompleteness::fromRepository($metrics);
    }

    private function countSeverity(Severity $severity): int
    {
        return \count(array_filter($this->findings, static fn(Finding $finding): bool => $finding->severity === $severity));
    }

    public function withHealth(ReportHealth $health): self
    {
        return new self(
            fileNamespaces: $this->fileNamespaces,
            findings: $this->findings,
            filesAnalyzed: $this->filesAnalyzed,
            filesSkipped: $this->filesSkipped,
            duration: $this->duration,
            metrics: $this->metrics,
            coverage: $this->coverage,
            suppressionComposition: $this->suppressionComposition,
            outOfScope: $this->outOfScope,
            projectScope: $this->projectScope,
            configurationDiagnostics: $this->configurationDiagnostics,
            computedMetricEvaluation: $this->computedMetricEvaluation,
            population: $this->population,
            health: $health,
        );
    }

    /**
     * Checks if report has no findings.
     */
    public function isEmpty(): bool
    {
        return $this->findings === [];
    }

    /**
     * Returns total number of findings.
     */
    public function getTotalFindings(): int
    {
        return \count($this->findings);
    }
}
