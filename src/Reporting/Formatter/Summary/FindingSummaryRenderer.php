<?php

declare(strict_types=1);

namespace Qualimetrix\Reporting\Formatter\Summary;

use Qualimetrix\Analysis\Evidence\Prioritization\Debt\DebtSummary;
use Qualimetrix\Analysis\Evidence\Prioritization\Debt\RemediationTimeRegistry;
use Qualimetrix\Analysis\Finding\Contract\Finding;
use Qualimetrix\Analysis\Finding\Contract\Severity;
use Qualimetrix\Reporting\DrillDown\OutOfScopeFindings;
use Qualimetrix\Reporting\Formatter\Ansi\AnsiColor;
use Qualimetrix\Reporting\FormatterContext;
use Qualimetrix\Reporting\Report;

/**
 * Renders the finding count summary with severity breakdown and tech debt.
 *
 * Under a `--namespace`/`--class` selection the report's findings are the
 * selection alone, already narrowed by the presenter; what it left out is
 * {@see Report::$outOfScope}. The line then speaks about the scope, names the
 * findings outside it, and takes its colour from the whole run, because those
 * are what decide the exit code.
 */
final class FindingSummaryRenderer
{
    public function __construct(
        private readonly RemediationTimeRegistry $remediationTimeRegistry,
    ) {}

    /**
     * @param list<string> $lines
     */
    public function render(Report $report, FormatterContext $context, AnsiColor $color, array &$lines): void
    {
        $findings = $report->findings;
        $outOfScope = $report->outOfScope;
        $counts = $this->countSeverities($findings);
        $runCounts = $this->withOutOfScope($counts, $outOfScope);

        if ($findings === []) {
            $label = $outOfScope === null ? 'No violations found.' : 'No violations in this scope.';
            $summary = $outOfScope === null || $outOfScope->total() === 0
                ? $label
                : $label . ' ' . $this->outOfScopePart($outOfScope);
            $lines[] = $this->colorizeSummary($summary, $runCounts, $color);
            $lines[] = '';

            return;
        }

        $parts = [$this->buildCountsPart(\count($findings), $counts, $outOfScope === null ? '' : ' in this scope')];

        $debtPart = $this->buildDebtPart($report, $context, $findings);
        if ($debtPart !== null) {
            $parts[] = $debtPart;
        }

        if ($outOfScope !== null && $outOfScope->total() > 0) {
            $parts[] = $this->outOfScopePart($outOfScope);
        }

        $summary = implode(' | ', $parts);

        $lines[] = $this->colorizeSummary($summary, $runCounts, $color);
        $lines[] = '';
    }

    private function outOfScopePart(OutOfScopeFindings $outOfScope): string
    {
        return \sprintf(
            '%d outside it (%s) decide the exit code',
            $outOfScope->total(),
            $this->severityDetails($outOfScope->errorCount, $outOfScope->warningCount, $outOfScope->infoCount),
        );
    }

    /**
     * @param array<string, int> $counts
     *
     * @return array<string, int>
     */
    private function withOutOfScope(array $counts, ?OutOfScopeFindings $outOfScope): array
    {
        if ($outOfScope === null) {
            return $counts;
        }

        $counts[Severity::Error->value] += $outOfScope->errorCount;
        $counts[Severity::Warning->value] += $outOfScope->warningCount;
        $counts[Severity::Info->value] += $outOfScope->infoCount;

        return $counts;
    }

    /**
     * @param list<Finding> $findings
     *
     * @return array<string, int>
     */
    private function countSeverities(array $findings): array
    {
        $counts = [
            Severity::Error->value => 0,
            Severity::Warning->value => 0,
            Severity::Info->value => 0,
        ];

        foreach ($findings as $v) {
            ++$counts[$v->severity->value];
        }

        return $counts;
    }

    /**
     * @param array<string, int> $counts
     */
    private function buildCountsPart(int $total, array $counts, string $scope): string
    {
        $part = \sprintf('%d violation%s%s', $total, $total === 1 ? '' : 's', $scope);

        $details = $this->severityDetails(
            $counts[Severity::Error->value],
            $counts[Severity::Warning->value],
            $counts[Severity::Info->value],
        );

        return $details !== '' ? $part . ' (' . $details . ')' : $part;
    }

    private function severityDetails(int $errors, int $warnings, int $info): string
    {
        $details = [];
        if ($errors > 0) {
            $details[] = \sprintf('%d error%s', $errors, $errors === 1 ? '' : 's');
        }
        if ($warnings > 0) {
            $details[] = \sprintf('%d warning%s', $warnings, $warnings === 1 ? '' : 's');
        }
        if ($info > 0) {
            $details[] = \sprintf('%d info', $info);
        }

        return implode(', ', $details);
    }

    /**
     * @param list<Finding> $findings
     */
    private function buildDebtPart(Report $report, FormatterContext $context, array $findings): ?string
    {
        if ($context->namespace === null && $context->class === null) {
            return $this->buildGlobalDebtPart($report);
        }

        return $this->buildScopedDebtPart($findings);
    }

    private function buildGlobalDebtPart(Report $report): ?string
    {
        if ($report->techDebtMinutes <= 0) {
            return null;
        }

        $debtStr = DebtSummary::formatMinutes($report->techDebtMinutes);
        if ($report->debtPer1kLoc !== null) {
            $debtStr .= \sprintf(' (%.1f min/kLOC to fix)', $report->debtPer1kLoc);
        }

        return \sprintf('Tech debt: %s', $debtStr);
    }

    /**
     * @param list<Finding> $findings
     */
    private function buildScopedDebtPart(array $findings): ?string
    {
        $scopedDebtMinutes = $this->calculateScopedDebt($findings);
        if ($scopedDebtMinutes <= 0) {
            return null;
        }

        return \sprintf('Tech debt: %s', DebtSummary::formatMinutes($scopedDebtMinutes));
    }

    /**
     * @param array<string, int> $counts
     */
    private function colorizeSummary(string $summary, array $counts, AnsiColor $color): string
    {
        if ($counts[Severity::Error->value] > 0) {
            return $color->boldRed($summary);
        }
        if ($counts[Severity::Warning->value] > 0) {
            return $color->boldYellow($summary);
        }
        if ($counts[Severity::Info->value] > 0) {
            return $color->boldCyan($summary);
        }

        return $color->boldGreen($summary);
    }

    /**
     * @param list<Finding> $findings
     */
    private function calculateScopedDebt(array $findings): int
    {
        $totalMinutes = 0;

        foreach ($findings as $finding) {
            $totalMinutes += $this->remediationTimeRegistry->getMinutesForFinding($finding);
        }

        return $totalMinutes;
    }
}
