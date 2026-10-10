<?php

declare(strict_types=1);

namespace Qualimetrix\Reporting;

use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Evaluation\ComputedMetricEvaluationSummary;

use Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Contract\Offender\WorstOffender;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Contract\Score\HealthScore;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricRepositoryInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\NamespaceTree;
use Qualimetrix\Analysis\Evidence\Prioritization\Impact\RankedIssue;
use Qualimetrix\Analysis\Finding\Contract\Finding;
use Qualimetrix\Analysis\Finding\Contract\Population\JudgedPopulation;
use Qualimetrix\Reporting\DrillDown\OutOfScopeFindings;
use Qualimetrix\Reporting\FindingProjection\SuppressionComposition;

/**
 * Value Object representing the analysis report.
 *
 * @qmx-threshold coupling.cbo warning=36 error=36 -- This immutable report carries the same
 *                publication snapshot to every formatter. Its population field and shared
 *                abstention narrator add intended edges.
 *                Splitting the transport record would distribute these same facts among
 *                objects every formatter still has to read. The exact limit retains one-edge headroom.
 */
final readonly class Report
{
    public JudgedPopulation $population;
    /**
     * @param list<Finding> $findings
     * @param array<string, HealthScore> $healthScores
     * @param list<WorstOffender> $worstNamespaces
     * @param list<WorstOffender> $worstClasses
     * @param list<RankedIssue> $topIssues
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
     */
    public function __construct(
        public array $findings,
        public int $filesAnalyzed,
        public int $filesSkipped,
        public float $duration,
        public int $errorCount,
        public int $warningCount,
        public ?MetricRepositoryInterface $metrics = null,
        public array $healthScores = [],
        public array $worstNamespaces = [],
        public array $worstClasses = [],
        public int $techDebtMinutes = 0,
        public ?float $debtPer1kLoc = null,
        public array $topIssues = [],
        public ?NamespaceTree $namespaceTree = null,
        public int $infoCount = 0,
        public ?ReportCoverage $coverage = null,
        public ?SuppressionComposition $suppressionComposition = null,
        public ?OutOfScopeFindings $outOfScope = null,
        public ?ReportProjectScope $projectScope = null,
        public array $configurationDiagnostics = [],
        public ComputedMetricEvaluationSummary $computedMetricEvaluation = new ComputedMetricEvaluationSummary(),
        ?JudgedPopulation $population = null,
    ) {
        $this->population = $population ?? JudgedPopulation::empty();
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
