<?php

declare(strict_types=1);

namespace Qualimetrix\Reporting\Formatter\Json;

use Composer\InstalledVersions;
use Qualimetrix\Core\Violation\Severity;
use Qualimetrix\Core\Violation\Violation;
use Qualimetrix\Reporting\Debt\DebtCalculator;
use Qualimetrix\Reporting\Formatter\FormatterInterface;
use Qualimetrix\Reporting\Formatter\Support\ViolationSorter;
use Qualimetrix\Reporting\FormatterContext;
use Qualimetrix\Reporting\GroupBy;
use Qualimetrix\Reporting\Report;

/**
 * Formats report as JSON with summary structure.
 *
 * Outputs health scores, worst offenders, and violations in a machine-readable
 * format suitable for AI agents, CI pipelines, and programmatic consumption.
 */
final class JsonFormatter implements FormatterInterface
{
    private const PACKAGE = 'qmx';
    private const ?int DEFAULT_VIOLATION_LIMIT = null;
    private const DEFAULT_TOP_OFFENDERS = 10;

    public function __construct(
        private readonly DebtCalculator $debtCalculator,
        private readonly JsonHealthSection $healthSection,
        private readonly JsonOffenderSection $offenderSection,
        private readonly JsonViolationSection $violationSection,
    ) {}

    public function format(Report $report, FormatterContext $context): string
    {
        $filteredViolations = $this->violationSection->sort($report->violations);

        $limit = $this->getViolationLimit($context);
        $outputViolations = $limit === null
            ? $filteredViolations
            : \array_slice($filteredViolations, 0, $limit);

        $topN = $this->getTopN($context);

        // When drill-down is active, compute summary from filtered violations
        $isDrillDown = $context->namespace !== null || $context->class !== null;

        $data = [
            'meta' => [
                'version' => InstalledVersions::getRootPackage()['pretty_version'] ?? 'dev',
                'package' => self::PACKAGE,
                'timestamp' => gmdate('c'),
            ],
            'summary' => $this->buildSummary($report, $filteredViolations, $isDrillDown),
            'health' => $this->healthSection->format($report, $context),
            'worstNamespaces' => $this->offenderSection->formatNamespaces(
                $report->worstNamespaces,
                $context,
                $topN,
            ),
            'worstClasses' => $this->offenderSection->formatClasses(
                $report,
                $context,
                $topN,
            ),
            'topIssues' => $this->formatTopIssues($report, $context),
            'violations' => $this->violationSection->format($outputViolations, $context),
            'violationsMeta' => [
                'total' => \count($filteredViolations),
                'shown' => \count($outputViolations),
                'limit' => $limit,
                'truncated' => $limit !== null && \count($filteredViolations) > $limit,
                'byRule' => $this->violationSection->countByRule($filteredViolations),
            ],
        ];

        // Add violationGroups when group-by is active (not None)
        if ($context->groupBy !== GroupBy::None) {
            $data['violationGroups'] = $this->buildViolationGroups(
                $outputViolations,
                $context,
            );
        }

        return json_encode($data, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR);
    }

    public function getName(): string
    {
        return 'json';
    }

    public function getDefaultGroupBy(): GroupBy
    {
        return GroupBy::None;
    }

    /**
     * Formats the top issues by impact section.
     *
     * @return list<array<string, mixed>>
     */
    private function formatTopIssues(Report $report, FormatterContext $context): array
    {
        if ($report->topIssues === [] || $context->topIssuesLimit === 0) {
            return [];
        }

        $filtered = $this->filterTopIssuesByContext($report->topIssues, $context);

        if ($filtered === []) {
            return [];
        }

        $issues = \array_slice($filtered, 0, $context->topIssuesLimit);
        $result = [];

        foreach ($issues as $rank => $issue) {
            $violation = $issue->violation;
            $result[] = [
                'rank' => $rank + 1,
                'file' => $context->relativizePath($violation->location->file),
                'line' => $violation->location->line,
                'symbol' => $violation->symbolPath->toString(),
                'rule' => $violation->ruleName,
                'severity' => $violation->severity->value,
                'message' => $violation->getDisplayMessage(),
                'impactScore' => round($issue->impactScore, 2),
                'classRank' => $issue->classRank !== null ? round($issue->classRank, 4) : null,
                'debtMinutes' => $issue->debtMinutes,
            ];
        }

        return $result;
    }

    /**
     * Filters top issues by namespace/class drill-down context.
     *
     * @param list<\Qualimetrix\Reporting\Impact\RankedIssue> $issues
     *
     * @return list<\Qualimetrix\Reporting\Impact\RankedIssue>
     */
    private function filterTopIssuesByContext(array $issues, FormatterContext $context): array
    {
        if ($context->namespace === null && $context->class === null) {
            return $issues;
        }

        return array_values(array_filter($issues, static function ($issue) use ($context): bool {
            $sp = $issue->violation->symbolPath;
            $ns = $sp->namespace ?? '';
            $type = $sp->type;

            if ($context->namespace !== null) {
                return $ns === $context->namespace || str_starts_with($ns, $context->namespace . '\\');
            }

            if ($context->class !== null && $type !== null) {
                $fqcn = $ns !== '' ? $ns . '\\' . $type : $type;

                return $fqcn === $context->class;
            }

            return false;
        }));
    }

    /**
     * Builds the summary section.
     *
     * When drill-down is active, violation counts reflect the filtered set.
     *
     * @param list<Violation> $filteredViolations
     *
     * @return array<string, mixed>
     */
    private function buildSummary(Report $report, array $filteredViolations, bool $isDrillDown): array
    {
        if ($isDrillDown) {
            $errorCount = 0;
            $warningCount = 0;
            foreach ($filteredViolations as $v) {
                if ($v->severity === Severity::Error) {
                    $errorCount++;
                } else {
                    $warningCount++;
                }
            }

            $debtSummary = $this->debtCalculator->calculate($filteredViolations);

            return [
                'filesAnalyzed' => $report->filesAnalyzed,
                'filesSkipped' => $report->filesSkipped,
                'duration' => round($report->duration, 3),
                'violationCount' => \count($filteredViolations),
                'errorCount' => $errorCount,
                'warningCount' => $warningCount,
                'techDebtMinutes' => $debtSummary->totalMinutes,
            ];
        }

        return [
            'filesAnalyzed' => $report->filesAnalyzed,
            'filesSkipped' => $report->filesSkipped,
            'duration' => round($report->duration, 3),
            'violationCount' => $report->getTotalViolations(),
            'errorCount' => $report->errorCount,
            'warningCount' => $report->warningCount,
            'techDebtMinutes' => $report->techDebtMinutes,
            'debtPer1kLoc' => $report->debtPer1kLoc,
        ];
    }

    /**
     * Builds grouped violation structure sorted by count descending.
     *
     * @param list<Violation> $violations Already limited violations
     *
     * @return array<string, array{count: int, violations: list<array<string, mixed>>}>
     */
    private function buildViolationGroups(array $violations, FormatterContext $context): array
    {
        $groups = ViolationSorter::group($violations, $context->groupBy);

        $result = [];

        foreach ($groups as $key => $groupViolations) {
            $result[$key] = [
                'count' => \count($groupViolations),
                'violations' => $this->violationSection->format($groupViolations, $context),
            ];
        }

        // Sort by count descending (worst first)
        uasort($result, static fn(array $a, array $b): int => $b['count'] <=> $a['count']);

        return $result;
    }

    /**
     * Returns the violation limit based on context.
     *
     * Priority: explicit --format-opt violations=N > --detail > default (50).
     * Returns null for "all violations" (no limit).
     */
    private function getViolationLimit(FormatterContext $context): ?int
    {
        // Support both --format-opt=violations=N and --format-opt=limit=N
        // "violations" takes precedence when both are set
        $opt = $context->getOption('violations');
        $isLimitAlias = false;

        if ($opt === '') {
            $opt = $context->getOption('limit');
            $isLimitAlias = $opt !== '';
        }

        if ($opt !== '') {
            if ($opt === 'all') {
                return null;
            }

            $parsed = filter_var($opt, \FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);

            if ($parsed === false) {
                return self::DEFAULT_VIOLATION_LIMIT;
            }

            // limit=0 means "no limit" (show all), violations=0 means "show none"
            if ($isLimitAlias && $parsed === 0) {
                return null;
            }

            return $parsed;
        }

        // --detail mode: respect limit (0 = all)
        if ($context->isDetailEnabled()) {
            return $context->detailLimit === 0 ? null : $context->detailLimit;
        }

        return self::DEFAULT_VIOLATION_LIMIT;
    }

    /**
     * Returns the top-N limit for worst offenders.
     */
    private function getTopN(FormatterContext $context): int
    {
        $opt = $context->getOption('top');

        if ($opt !== '') {
            $parsed = filter_var($opt, \FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

            return $parsed !== false ? $parsed : self::DEFAULT_TOP_OFFENDERS;
        }

        return self::DEFAULT_TOP_OFFENDERS;
    }
}
