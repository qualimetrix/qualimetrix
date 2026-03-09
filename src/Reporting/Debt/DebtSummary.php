<?php

declare(strict_types=1);

namespace AiMessDetector\Reporting\Debt;

/**
 * Value Object representing aggregated technical debt information.
 */
final readonly class DebtSummary
{
    /**
     * @param int $totalMinutes Total remediation time in minutes
     * @param array<string, int> $perFile Remediation time per file (file path -> minutes)
     * @param array<string, int> $perRule Remediation time per rule (rule name -> minutes)
     */
    public function __construct(
        public int $totalMinutes,
        public array $perFile,
        public array $perRule,
    ) {}

    /**
     * Formats the total remediation time as a human-readable string.
     *
     * Examples: "0min", "45min", "1h 30min", "1d 2h 15min"
     */
    public function formatTotal(): string
    {
        return self::formatMinutes($this->totalMinutes);
    }

    /**
     * Formats the given number of minutes as a human-readable string.
     *
     * Uses 8-hour work days for day calculation.
     * Examples: "0min", "45min", "1h 30min", "1d 2h 15min"
     */
    public static function formatMinutes(int $minutes): string
    {
        if ($minutes <= 0) {
            return '0min';
        }

        $days = intdiv($minutes, 480); // 8 hours = 1 day
        $remaining = $minutes % 480;
        $hours = intdiv($remaining, 60);
        $mins = $remaining % 60;

        $parts = [];

        if ($days > 0) {
            $parts[] = "{$days}d";
        }

        if ($hours > 0) {
            $parts[] = "{$hours}h";
        }

        if ($mins > 0) {
            $parts[] = "{$mins}min";
        }

        return implode(' ', $parts);
    }
}
