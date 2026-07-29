<?php

declare(strict_types=1);

namespace Qualimetrix\Reporting\Formatter\Summary;

use Qualimetrix\Core\Violation\Severity;
use Qualimetrix\Core\Violation\Violation;
use Qualimetrix\Reporting\Debt\DebtSummary;
use Qualimetrix\Reporting\Debt\RemediationTimeRegistry;
use Qualimetrix\Reporting\Filter\ViolationFilter;
use Qualimetrix\Reporting\Formatter\Support\AnsiColor;
use Qualimetrix\Reporting\FormatterContext;
use Qualimetrix\Reporting\Report;

/**
 * Renders the violation count summary with severity breakdown and tech debt.
 */
final class ViolationSummaryRenderer
{
    public function __construct(
        private readonly ViolationFilter $violationFilter,
        private readonly RemediationTimeRegistry $remediationTimeRegistry,
    ) {}

    /**
     * @param list<string> $lines
     */
    public function render(Report $report, FormatterContext $context, AnsiColor $color, array &$lines): void
    {
        $violations = $this->violationFilter->filterViolations($report->violations, $context);

        if ($violations === []) {
            $this->renderEmptyState($report, $context, $color, $lines);

            return;
        }

        $counts = $this->countSeverities($violations);

        $parts = [$this->buildCountsPart(\count($violations), $counts)];

        $debtPart = $this->buildDebtPart($report, $context, $violations);
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
     * @param list<Violation> $violations
     *
     * @return array<string, int>
     */
    private function countSeverities(array $violations): array
    {
        $counts = [
            Severity::Error->value => 0,
            Severity::Warning->value => 0,
            Severity::Info->value => 0,
        ];

        foreach ($violations as $v) {
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
     * @param list<Violation> $violations
     */
    private function buildDebtPart(Report $report, FormatterContext $context, array $violations): ?string
    {
        if ($context->namespace === null && $context->class === null) {
            return $this->buildGlobalDebtPart($report);
        }

        return $this->buildScopedDebtPart($violations);
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
     * @param list<Violation> $violations
     */
    private function buildScopedDebtPart(array $violations): ?string
    {
        $scopedDebtMinutes = $this->calculateScopedDebt($violations);
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
     * @param list<Violation> $violations
     */
    private function calculateScopedDebt(array $violations): int
    {
        $totalMinutes = 0;

        foreach ($violations as $violation) {
            $totalMinutes += $this->remediationTimeRegistry->getMinutesForViolation($violation);
        }

        return $totalMinutes;
    }
}
