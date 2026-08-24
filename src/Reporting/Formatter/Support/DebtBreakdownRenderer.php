<?php

declare(strict_types=1);

namespace Qualimetrix\Reporting\Formatter\Support;

use Qualimetrix\Analysis\Evidence\Prioritization\Debt\DebtCalculator;
use Qualimetrix\Analysis\Evidence\Prioritization\Debt\DebtSummary;
use Qualimetrix\Analysis\Finding\Contract\Finding;

/** Renders technical-debt totals grouped by rule. */
final readonly class DebtBreakdownRenderer
{
    public function __construct(private DebtCalculator $debtCalculator) {}

    /**
     * @param list<Finding> $findings
     * @param list<Finding>|null $allFindings
     */
    public function render(array $findings, ?array $allFindings = null): string
    {
        $debtFindings = $allFindings ?? $findings;
        $debt = $this->debtCalculator->calculate($debtFindings);
        if ($debt->perRule === []) {
            return '';
        }

        $lines = ['Technical debt by rule:'];
        $violationCounts = [];
        foreach ($debtFindings as $finding) {
            $violationCounts[$finding->ruleName] = ($violationCounts[$finding->ruleName] ?? 0) + 1;
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
