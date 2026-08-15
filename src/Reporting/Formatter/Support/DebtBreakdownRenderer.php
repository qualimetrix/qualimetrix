<?php

declare(strict_types=1);

namespace Qualimetrix\Reporting\Formatter\Support;

use Qualimetrix\Analysis\Evidence\Prioritization\Debt\DebtCalculator;
use Qualimetrix\Analysis\Evidence\Prioritization\Debt\DebtSummary;
use Qualimetrix\Analysis\Finding\Contract\Violation;

/** Renders technical-debt totals grouped by rule. */
final readonly class DebtBreakdownRenderer
{
    public function __construct(private DebtCalculator $debtCalculator) {}

    /**
     * @param list<Violation> $violations
     * @param list<Violation>|null $allViolations
     */
    public function render(array $violations, ?array $allViolations = null): string
    {
        $debtViolations = $allViolations ?? $violations;
        $debt = $this->debtCalculator->calculate($debtViolations);
        if ($debt->perRule === []) {
            return '';
        }

        $lines = ['Technical debt by rule:'];
        $violationCounts = [];
        foreach ($debtViolations as $violation) {
            $violationCounts[$violation->ruleName] = ($violationCounts[$violation->ruleName] ?? 0) + 1;
        }

        $perRule = $debt->perRule;
        arsort($perRule);

        foreach ($perRule as $ruleName => $minutes) {
            $count = $violationCounts[$ruleName] ?? 0;
            $lines[] = \sprintf(
                '  %-40s ~%-8s (%d %s)',
                $ruleName,
                DebtSummary::formatMinutes($minutes),
                $count,
                $count === 1 ? 'violation' : 'violations',
            );
        }

        return implode("\n", $lines);
    }
}
