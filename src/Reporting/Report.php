<?php

declare(strict_types=1);

namespace Qualimetrix\Reporting;

use Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Contract\Offender\WorstOffender;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Contract\Score\HealthScore;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricRepositoryInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\NamespaceTree;
use Qualimetrix\Analysis\Evidence\Prioritization\Impact\RankedIssue;
use Qualimetrix\Analysis\Finding\Contract\Finding;
use Qualimetrix\Analysis\Finding\Contract\Severity;
use Qualimetrix\Reporting\FindingProjection\SuppressionComposition;

/**
 * Value Object representing the analysis report.
 *
 * @qmx-threshold coupling.cbo warning=31 error=31 -- Report is the transport VO every formatter's
 *                `format(Report, FormatterContext)` signature depends on, and the type every
 *                pipeline consumer that builds one depends on in turn; Ш6's `SuppressionComposition`
 *                field and its eleventh formatter consumer (`SuppressedFormatter`) are the intentional
 *                cause of the two-point rise from the previously baseline-accepted 28. A point
 *                threshold replaces that baseline entry rather than raising it, per the same reasoning
 *                already applied to the sibling hub {@see FormatterContext}.
 */
final readonly class Report
{
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
    ) {}

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

    /**
     * Returns findings filtered by severity.
     *
     * @return list<Finding>
     */
    public function getFindingsBySeverity(Severity $severity): array
    {
        return array_values(array_filter(
            $this->findings,
            static fn(Finding $v): bool => $v->severity === $severity,
        ));
    }
}
