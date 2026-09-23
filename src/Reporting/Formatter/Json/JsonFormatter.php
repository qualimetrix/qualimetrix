<?php

declare(strict_types=1);

namespace Qualimetrix\Reporting\Formatter\Json;

use LogicException;
use Qualimetrix\Analysis\Evidence\Prioritization\Debt\DebtCalculator;
use Qualimetrix\Analysis\Finding\Contract\Finding;
use Qualimetrix\Analysis\Finding\Contract\Severity;
use Qualimetrix\Core\ProductIdentity;
use Qualimetrix\Reporting\DrillDown\OutOfScopeFindings;
use Qualimetrix\Reporting\Formatter\FormatOptionKeysInterface;
use Qualimetrix\Reporting\Formatter\FormatOptionValue;
use Qualimetrix\Reporting\Formatter\FormatterInterface;
use Qualimetrix\Reporting\Formatter\Ordering\FindingSorter;
use Qualimetrix\Reporting\Formatter\PublishedUtf8;
use Qualimetrix\Reporting\FormatterContext;
use Qualimetrix\Reporting\GroupBy;
use Qualimetrix\Reporting\Report;

/**
 * Formats report as JSON with summary structure.
 *
 * Outputs health scores, worst offenders, and findings in a machine-readable
 * format suitable for AI agents, CI pipelines, and programmatic consumption.
 */
final class JsonFormatter implements FormatterInterface, FormatOptionKeysInterface
{
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

        $data = [
            'meta' => ProductIdentity::meta(gmdate('c')),
            'summary' => $this->buildSummary($report, $filteredFindings),
            'outOfScope' => $this->buildOutOfScope($report->outOfScope),
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

        return PublishedUtf8::encodeJsonObject($data, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES);
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
     * `violations` and `limit` bound the finding list here; `top` and `rank-by`
     * are read by {@see JsonOffenderSection} on this formatter's behalf.
     */
    public function formatOptionKeys(): array
    {
        return ['limit', 'rank-by', 'top', 'violations'];
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

        // Ranked from the report's findings, which the presenter has already
        // narrowed to any --namespace/--class selection: filtering again here
        // would be a second copy of that rule, free to drift from the first.
        $issues = \array_slice($report->topIssues, 0, $context->topIssuesLimit);
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
                'message' => $finding->message,
                'recommendation' => $finding->recommendation,
                'impactScore' => round($issue->impactScore, 2),
                'coupling.class-rank' => $issue->classRank !== null ? round($issue->classRank, 4) : null,
                'debtMinutes' => $issue->debtMinutes,
            ];
        }

        return $result;
    }

    /**
     * Builds the summary section.
     *
     * Under a drill-down, finding counts reflect the selection. A drill-down
     * is what `outOfScope` says it is — the same fact the `outOfScope` key
     * publishes, so the two sections cannot disagree about whether one ran.
     *
     * @param list<Finding> $filteredFindings
     *
     * @return array<string, mixed>
     */
    private function buildSummary(Report $report, array $filteredFindings): array
    {
        if ($report->outOfScope !== null) {
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
                // Kept, as null: the selection's debt over the whole project's
                // LOC would mix two scopes, and a key that vanishes changes the
                // document's shape with the command line.
                'debtPer1kLoc' => null,
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
     * What a `--namespace`/`--class` selection left out of `summary`: the exit
     * code is resolved over both. `null` without a selection, and zeroes when
     * the selection left nothing out — present either way, so the document's
     * shape does not move with the command line.
     *
     * @return array{violationCount: int, errorCount: int, warningCount: int, infoCount: int}|null
     */
    private function buildOutOfScope(?OutOfScopeFindings $outOfScope): ?array
    {
        return $outOfScope === null ? null : [
            'violationCount' => $outOfScope->total(),
            'errorCount' => $outOfScope->errorCount,
            'warningCount' => $outOfScope->warningCount,
            'infoCount' => $outOfScope->infoCount,
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
     * Priority: an explicit `violations`/`limit` format option > --detail >
     * default (no limit). Returns null for "all findings" (no limit).
     */
    private function getViolationLimit(FormatterContext $context): ?int
    {
        $violations = $context->getOption('violations');
        $limit = $context->getOption('limit');

        if ($violations !== '' && $limit !== '') {
            throw new LogicException(
                '--format-opt violations and limit reached the formatter together; the command line must refuse the pair first.',
            );
        }

        if ($violations !== '') {
            // violations=0 means "show none"
            return FormatOptionValue::limit('violations', $violations);
        }

        if ($limit !== '') {
            // limit=0 means "no limit" (show all)
            $parsed = FormatOptionValue::limit('limit', $limit);

            return $parsed === 0 ? null : $parsed;
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

        return $opt !== '' ? FormatOptionValue::positive('top', $opt) : self::DEFAULT_TOP_OFFENDERS;
    }
}
