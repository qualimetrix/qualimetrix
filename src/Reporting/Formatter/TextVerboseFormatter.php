<?php

declare(strict_types=1);

namespace AiMessDetector\Reporting\Formatter;

use AiMessDetector\Core\Violation\Severity;
use AiMessDetector\Core\Violation\Violation;
use AiMessDetector\Reporting\Report;

/**
 * Formats report as human-readable verbose text output.
 *
 * Violations are sorted by severity (errors first), then by file and line.
 * Use --format=text for compact, parseable output.
 */
final class TextVerboseFormatter implements FormatterInterface
{
    private const HEADER = 'AI Mess Detector Report';
    private const SEPARATOR_DOUBLE = '==================================================';
    private const SEPARATOR_SINGLE = '--------------------------------------------------';

    public function format(Report $report): string
    {
        $lines = [];

        // Header
        $lines[] = self::HEADER;
        $lines[] = self::SEPARATOR_DOUBLE;
        $lines[] = '';

        // Violations or empty message
        if ($report->isEmpty()) {
            $lines[] = 'No violations found.';
            $lines[] = '';
        } else {
            $lines[] = 'Violations:';
            $lines[] = '';

            $sortedViolations = $this->sortViolations($report->violations);

            foreach ($sortedViolations as $violation) {
                $lines = [...$lines, ...$this->formatViolation($violation)];
            }
        }

        // Summary
        $lines[] = self::SEPARATOR_SINGLE;
        $lines[] = $this->formatSummary($report);

        return implode("\n", $lines) . "\n";
    }

    public function getName(): string
    {
        return 'text-verbose';
    }

    /**
     * Sort violations: errors first, then by file path, then by line number.
     *
     * @param list<Violation> $violations
     *
     * @return list<Violation>
     */
    private function sortViolations(array $violations): array
    {
        usort($violations, static function (Violation $a, Violation $b): int {
            // Errors before warnings
            $severityOrder = self::severityOrder($a->severity) <=> self::severityOrder($b->severity);
            if ($severityOrder !== 0) {
                return $severityOrder;
            }

            // Then by file path
            $fileOrder = $a->location->file <=> $b->location->file;
            if ($fileOrder !== 0) {
                return $fileOrder;
            }

            // Then by line number
            return ($a->location->line ?? 0) <=> ($b->location->line ?? 0);
        });

        return $violations;
    }

    private static function severityOrder(Severity $severity): int
    {
        return match ($severity) {
            Severity::Error => 0,
            Severity::Warning => 1,
        };
    }

    /**
     * @return list<string>
     */
    private function formatViolation(Violation $violation): array
    {
        $severity = $this->formatSeverity($violation->severity);
        $location = $violation->location->toString();
        $symbol = $violation->symbolPath->toString();

        $lines = [];
        $lines[] = \sprintf('  [%s] %s', $severity, $location);
        $lines[] = \sprintf('    %s', $symbol);
        $lines[] = \sprintf('    Rule: %s', $violation->ruleName);
        $lines[] = \sprintf('    %s', $violation->message);
        $lines[] = '';

        return $lines;
    }

    private function formatSeverity(Severity $severity): string
    {
        return match ($severity) {
            Severity::Error => 'ERROR',
            Severity::Warning => 'WARNING',
        };
    }

    private function formatSummary(Report $report): string
    {
        return \sprintf(
            'Files: %d analyzed, %d skipped | Errors: %d | Warnings: %d | Time: %.2fs',
            $report->filesAnalyzed,
            $report->filesSkipped,
            $report->errorCount,
            $report->warningCount,
            $report->duration,
        );
    }
}
