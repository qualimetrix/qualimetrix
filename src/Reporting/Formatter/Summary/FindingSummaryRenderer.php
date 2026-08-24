<?php

declare(strict_types=1);

namespace Qualimetrix\Reporting\Formatter\Summary;

use Qualimetrix\Analysis\Evidence\Prioritization\Debt\DebtSummary;
use Qualimetrix\Analysis\Evidence\Prioritization\Debt\RemediationTimeRegistry;
use Qualimetrix\Analysis\Finding\Contract\Finding;
use Qualimetrix\Analysis\Finding\Contract\Severity;
use Qualimetrix\Reporting\Filter\FindingFilter;
use Qualimetrix\Reporting\Formatter\Support\AnsiColor;
use Qualimetrix\Reporting\FormatterContext;
use Qualimetrix\Reporting\Report;

/**
 * Renders the finding count summary with severity breakdown and tech debt.
 */
final class FindingSummaryRenderer
{
    public function __construct(
        private readonly FindingFilter $findingFilter,
        private readonly RemediationTimeRegistry $remediationTimeRegistry,
    ) {}

    /**
     * @param list<string> $lines
     */
    public function render(Report $report, FormatterContext $context, AnsiColor $color, array &$lines): void
    {
        $findings = $this->findingFilter->filterFindings($report->findings, $context);

        if ($findings === []) {
            $this->renderEmptyState($report, $context, $color, $lines);

            return;
        }

        $counts = $this->countSeverities($findings);

        $parts = [$this->buildCountsPart(\count($findings), $counts)];

        $debtPart = $this->buildDebtPart($report, $context, $findings);
        if ($debtPart !== null) {
            $parts[] = $debtPart;
        }

        $summary = implode(' | ', $parts);

        $lines[] = $this->colorizeSummary($summary, $counts, $color);
        $lines[] = '';
    }

    /**
     * @param list<string> $lines
     */
    private function renderEmptyState(Report $report, FormatterContext $context, AnsiColor $color, array &$lines): void
    {
        if ($report->isEmpty()) {
            $lines[] = $color->boldGreen('No violations found.');
        } elseif ($context->namespace !== null || $context->class !== null) {
            $lines[] = $color->boldGreen('No violations in this scope.');
        }
        $lines[] = '';
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
    private function buildCountsPart(int $total, array $counts): string
    {
        $part = \sprintf('%d violation%s', $total, $total === 1 ? '' : 's');

        $details = [];
        $errors = $counts[Severity::Error->value];
        $warnings = $counts[Severity::Warning->value];
        $info = $counts[Severity::Info->value];

        if ($errors > 0) {
            $details[] = \sprintf('%d error%s', $errors, $errors === 1 ? '' : 's');
        }
        if ($warnings > 0) {
            $details[] = \sprintf('%d warning%s', $warnings, $warnings === 1 ? '' : 's');
        }
        if ($info > 0) {
            $details[] = \sprintf('%d info', $info);
        }

        if ($details !== []) {
            $part .= ' (' . implode(', ', $details) . ')';
        }

        return $part;
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
