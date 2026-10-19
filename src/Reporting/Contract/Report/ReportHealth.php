<?php

declare(strict_types=1);

namespace Qualimetrix\Reporting\Contract\Report;

use Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Contract\Offender\WorstOffender;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Contract\Score\HealthScore;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\NamespaceTree;
use Qualimetrix\Analysis\Evidence\Prioritization\Impact\RankedIssue;

/** Health, debt and impact enrichment published as one report value. */
final readonly class ReportHealth
{
    /**
     * @param array<string, HealthScore> $healthScores
     * @param list<WorstOffender> $worstNamespaces
     * @param list<WorstOffender> $worstClasses
     * @param list<RankedIssue> $topIssues
     */
    public function __construct(
        public array $healthScores = [],
        public array $worstNamespaces = [],
        public array $worstClasses = [],
        public int $techDebtMinutes = 0,
        public ?float $debtPer1kLoc = null,
        public array $topIssues = [],
        public ?NamespaceTree $namespaceTree = null,
    ) {}
}
