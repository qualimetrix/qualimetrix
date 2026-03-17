<?php

declare(strict_types=1);

namespace AiMessDetector\Reporting\Formatter\Summary;

use AiMessDetector\Core\Violation\Severity;
use AiMessDetector\Core\Violation\Violation;
use AiMessDetector\Reporting\Debt\DebtSummary;
use AiMessDetector\Reporting\Debt\RemediationTimeRegistry;
use AiMessDetector\Reporting\Filter\ViolationFilter;
use AiMessDetector\Reporting\Formatter\Support\AnsiColor;
use AiMessDetector\Reporting\FormatterContext;
use AiMessDetector\Reporting\Report;

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
            if ($report->isEmpty()) {
                $lines[] = $color->boldGreen('No violations found.');
            } elseif ($context->namespace !== null || $context->class !== null) {
                $lines[] = $color->boldGreen('No violations in this scope.');
            }
            $lines[] = '';

            return;
        }

        $total = \count($violations);
        $errors = 0;
        $warnings = 0;
        foreach ($violations as $v) {
            if ($v->severity === Severity::Error) {
                $errors++;
            } else {
                $warnings++;
            }
        }

        $parts = [];
        $parts[] = \sprintf('%d violation%s', $total, $total === 1 ? '' : 's');

        $details = [];
        if ($errors > 0) {
            $details[] = \sprintf('%d error%s', $errors, $errors === 1 ? '' : 's');
        }
        if ($warnings > 0) {
            $details[] = \sprintf('%d warning%s', $warnings, $warnings === 1 ? '' : 's');
        }
        if ($details !== []) {
            $parts[0] .= ' (' . implode(', ', $details) . ')';
        }

        if ($context->namespace === null && $context->class === null) {
            if ($report->techDebtMinutes > 0) {
                $debtStr = DebtSummary::formatMinutes($report->techDebtMinutes);
                if ($report->debtPer1kLoc !== null) {
                    $debtStr .= \sprintf(' (%.1f min/kLOC to fix)', $report->debtPer1kLoc);
                }
                $parts[] = \sprintf('Tech debt: %s', $debtStr);
            }
        } else {
            $scopedDebtMinutes = $this->calculateScopedDebt($violations);
            if ($scopedDebtMinutes > 0) {
                $parts[] = \sprintf('Tech debt: %s', DebtSummary::formatMinutes($scopedDebtMinutes));
            }
        }

        $summary = implode(' | ', $parts);

        if ($errors > 0) {
            $lines[] = $color->boldRed($summary);
        } elseif ($warnings > 0) {
            $lines[] = $color->boldYellow($summary);
        } else {
            $lines[] = $color->boldGreen($summary);
        }

        $lines[] = '';
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
