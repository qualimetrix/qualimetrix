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

/**
 * Value Object representing the analysis report.
 */
final readonly class Report
{
    /**
     * @param list<Finding> $findings
     * @param array<string, HealthScore> $healthScores
     * @param list<WorstOffender> $worstNamespaces
     * @param list<WorstOffender> $worstClasses
     * @param list<RankedIssue> $topIssues
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
