<?php

declare(strict_types=1);

namespace Qualimetrix\Reporting\Debt;

use Qualimetrix\Core\Violation\Violation;

/**
 * Calculates technical debt summary from a list of violations.
 */
final readonly class DebtCalculator
{
    public function __construct(
        private RemediationTimeRegistry $registry,
    ) {}

    /**
     * Calculates the technical debt summary for the given violations.
     *
     * @param list<Violation> $violations
     */
    public function calculate(array $violations): DebtSummary
    {
        $totalMinutes = 0;
        /** @var array<string, int> $perFile */
        $perFile = [];
        /** @var array<string, int> $perRule */
        $perRule = [];

        foreach ($violations as $violation) {
            $minutes = $this->registry->getMinutesForViolation($violation);
            $totalMinutes += $minutes;

            $file = $violation->location->file;
            if ($file !== '') {
                $perFile[$file] = ($perFile[$file] ?? 0) + $minutes;
            }

            $rule = $violation->ruleName;
            $perRule[$rule] = ($perRule[$rule] ?? 0) + $minutes;
        }

        return new DebtSummary($totalMinutes, $perFile, $perRule);
    }
}
