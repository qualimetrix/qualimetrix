<?php

declare(strict_types=1);

namespace Qualimetrix\Reporting\Formatter\Json;

use Qualimetrix\Analysis\Evidence\Prioritization\Debt\DebtCalculator;
use Qualimetrix\Analysis\Finding\Contract\Finding;
use Qualimetrix\Analysis\Finding\Contract\Severity;
use Qualimetrix\Core\Version;
use Qualimetrix\Reporting\Formatter\FormatterInterface;
use Qualimetrix\Reporting\Formatter\Support\FindingSorter;
use Qualimetrix\Reporting\FormatterContext;
use Qualimetrix\Reporting\GroupBy;
use Qualimetrix\Reporting\Report;

/**
 * Formats report as JSON with summary structure.
 *
 * Outputs health scores, worst offenders, and findings in a machine-readable
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
        private readonly JsonFindingSection $findingSection,
    ) {}

    public function format(Report $report, FormatterContext $context): string
    {
        $filteredFindings = $this->findingSection->sort($report->findings);

        $limit = $this->getViolationLimit($context);
        $outputFindings = $limit === null
            ? $filteredFindings
            : \array_slice($filteredFindings, 0, $limit);

        $topN = $this->getTopN($context);

        // When drill-down is active, compute summary from filtered findings
        $isDrillDown = $context->namespace !== null || $context->class !== null;

        $data = [
            'meta' => [
                'version' => Version::get(),
                'package' => self::PACKAGE,
                'timestamp' => gmdate('c'),
            ],
            'summary' => $this->buildSummary($report, $filteredFindings, $isDrillDown),
            'coverage' => $report->coverage?->toArray(),
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
            'violations' => $this->findingSection->format($outputFindings, $context),
            'violationsMeta' => [
                'total' => \count($filteredFindings),
                'shown' => \count($outputFindings),
                'limit' => $limit,
                'truncated' => $limit !== null && \count($filteredFindings) > $limit,
                'byRule' => $this->findingSection->countByRule($filteredFindings),
            ],
        ];

        if ($context->groupBy !== GroupBy::None) {
            $data['violationGroups'] = $this->buildFindingGroups(
                $outputFindings,
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
            $finding = $issue->finding;
            $result[] = [
                'rank' => $rank + 1,
                'file' => $finding->location->file === null
                    ? null
                    : $context->relativizePath($finding->location->file),
                'line' => $finding->location->line,
                'symbol' => $finding->symbolPath->toString(),
                'rule' => $finding->ruleName,
                'severity' => $finding->severity->value,
                'message' => $finding->getDisplayMessage(),
                'impactScore' => round($issue->impactScore, 2),
                'coupling.class-rank' => $issue->classRank !== null ? round($issue->classRank, 4) : null,
                'debtMinutes' => $issue->debtMinutes,
            ];
        }

        return $result;
    }

    /**
     * Filters top issues by namespace/class drill-down context.
     *
     * @param list<\Qualimetrix\Analysis\Evidence\Prioritization\Impact\RankedIssue> $issues
     *
     * @return list<\Qualimetrix\Analysis\Evidence\Prioritization\Impact\RankedIssue>
     */
    private function filterTopIssuesByContext(array $issues, FormatterContext $context): array
    {
        if ($context->namespace === null && $context->class === null) {
            return $issues;
        }

        return array_values(array_filter($issues, static function ($issue) use ($context): bool {
            $sp = $issue->finding->symbolPath;
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
     * When drill-down is active, finding counts reflect the filtered set.
     *
     * @param list<Finding> $filteredFindings
     *
     * @return array<string, mixed>
     */
    private function buildSummary(Report $report, array $filteredFindings, bool $isDrillDown): array
    {
        if ($isDrillDown) {
            $errorCount = 0;
            $warningCount = 0;
            $infoCount = 0;
            foreach ($filteredFindings as $v) {
                match ($v->severity) {
                    Severity::Error => $errorCount++,
                    Severity::Warning => $warningCount++,
                    Severity::Info => $infoCount++,
                };
            }

            $debtSummary = $this->debtCalculator->calculate($filteredFindings);

            return [
                'filesAnalyzed' => $report->filesAnalyzed,
                'filesSkipped' => $report->filesSkipped,
                'duration' => round($report->duration, 3),
                'violationCount' => \count($filteredFindings),
                'errorCount' => $errorCount,
                'warningCount' => $warningCount,
                'infoCount' => $infoCount,
                'techDebtMinutes' => $debtSummary->totalMinutes,
            ];
        }

        return [
            'filesAnalyzed' => $report->filesAnalyzed,
            'filesSkipped' => $report->filesSkipped,
            'duration' => round($report->duration, 3),
            'violationCount' => $report->getTotalFindings(),
            'errorCount' => $report->errorCount,
            'warningCount' => $report->warningCount,
            'infoCount' => $report->infoCount,
            'techDebtMinutes' => $report->techDebtMinutes,
            'debtPer1kLoc' => $report->debtPer1kLoc,
        ];
    }

    /**
     * Builds grouped finding structure sorted by count descending.
     *
     * @param list<Finding> $findings Already limited findings
     *
     * @return array<string, array{count: int, violations: list<array<string, mixed>>}>
     */
    private function buildFindingGroups(array $findings, FormatterContext $context): array
    {
        $groups = FindingSorter::group($findings, $context->groupBy);

        $result = [];

        foreach ($groups as $key => $groupFindings) {
            $result[$key] = [
                'count' => \count($groupFindings),
                'violations' => $this->findingSection->format($groupFindings, $context),
            ];
        }

        // Sort by count descending (worst first)
        uasort($result, static fn(array $a, array $b): int => $b['count'] <=> $a['count']);

        return $result;
    }

    /**
     * Returns the finding limit based on context.
     *
     * Priority: explicit --format-opt violations=N > --detail > default (50).
     * Returns null for "all findings" (no limit).
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
