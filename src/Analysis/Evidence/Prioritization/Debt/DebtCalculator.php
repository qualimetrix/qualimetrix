<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Prioritization\Debt;

use Qualimetrix\Analysis\Finding\Contract\Finding;

/**
 * Calculates technical debt summary from a list of findings.
 */
final readonly class DebtCalculator
{
    public function __construct(
        private RemediationTimeRegistry $registry,
    ) {}

    /**
     * Calculates the technical debt summary for the given findings.
     *
     * @param list<Finding> $findings
     */
    public function calculate(array $findings): DebtSummary
    {
        $totalMinutes = 0;
        /** @var array<string, int> $perFile */
        $perFile = [];
        /** @var array<string, int> $perRule */
        $perRule = [];

        foreach ($findings as $finding) {
            $minutes = $this->registry->getMinutesForFinding($finding);
            $totalMinutes += $minutes;

            $file = $finding->location->pathString();
            if ($file !== '') {
                $perFile[$file] = ($perFile[$file] ?? 0) + $minutes;
            }

            $rule = $finding->ruleName;
            $perRule[$rule] = ($perRule[$rule] ?? 0) + $minutes;
        }

        return new DebtSummary($totalMinutes, $perFile, $perRule);
    }
}
