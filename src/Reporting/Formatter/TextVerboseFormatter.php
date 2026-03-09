<?php

declare(strict_types=1);

namespace AiMessDetector\Reporting\Formatter;

use AiMessDetector\Core\Violation\Severity;
use AiMessDetector\Core\Violation\Violation;
use AiMessDetector\Reporting\AnsiColor;
use AiMessDetector\Reporting\Debt\DebtCalculator;
use AiMessDetector\Reporting\Debt\DebtSummary;
use AiMessDetector\Reporting\FormatterContext;
use AiMessDetector\Reporting\GroupBy;
use AiMessDetector\Reporting\Report;
use AiMessDetector\Reporting\ViolationSorter;
use LogicException;

/**
 * Formats report as human-readable verbose text output.
 *
 * Default grouping: by file. Supports all GroupBy modes.
 * Use --format=text for compact, parseable output.
 */
final class TextVerboseFormatter implements FormatterInterface
{
    private const HEADER = 'AI Mess Detector Report';
    private const SEPARATOR = '──────────────────────────────────────────────────';

    public function __construct(
        private readonly DebtCalculator $debtCalculator,
    ) {}

    public function format(Report $report, FormatterContext $context): string
    {
        $color = new AnsiColor($context->useColor);
        $lines = [];

        // Header
        $lines[] = $color->bold(self::HEADER);
        $lines[] = self::SEPARATOR;
        $lines[] = '';

        if ($report->isEmpty()) {
            $lines[] = $color->boldGreen('No violations found.');
            $lines[] = '';
        } else {
            $sorted = ViolationSorter::sort($report->violations, $context->groupBy);

            if ($context->groupBy === GroupBy::None) {
                $this->renderFlat($sorted, $color, $context, $lines);
            } else {
                $groups = ViolationSorter::group($sorted, $context->groupBy);
                $this->renderGrouped($groups, $context->groupBy, $color, $context, $lines);
            }
        }

        // Summary
        $lines[] = self::SEPARATOR;
        $lines[] = $this->formatSummary($report, $color);

        // Technical debt breakdown
        $debt = $this->debtCalculator->calculate($report->violations);
        $lines[] = $this->formatDebtBreakdown($debt, $report);

        return implode("\n", $lines) . "\n";
    }

    public function getName(): string
    {
        return 'text-verbose';
    }

    public function getDefaultGroupBy(): GroupBy
    {
        return GroupBy::File;
    }

    /**
     * @param list<Violation> $violations
     * @param list<string> $lines
     */
    private function renderFlat(array $violations, AnsiColor $color, FormatterContext $context, array &$lines): void
    {
        foreach ($violations as $violation) {
            $this->renderViolation($violation, $color, $context, $lines, showFile: true);
        }
    }

    /**
     * @param array<string, list<Violation>> $groups
     * @param list<string> $lines
     */
    private function renderGrouped(array $groups, GroupBy $groupBy, AnsiColor $color, FormatterContext $context, array &$lines): void
    {
        foreach ($groups as $key => $violations) {
            $count = \count($violations);
            $header = match ($groupBy) {
                GroupBy::File => \sprintf('%s (%d)', $color->bold($key !== '' ? $context->relativizePath($key) : '<no file>'), $count),
                GroupBy::Rule => \sprintf('%s (%d)', $color->bold($key !== '' ? $key : '<unknown>'), $count),
                GroupBy::Severity => \sprintf('%s (%d)', $this->formatSeverityLabel($key, $color), $count),
                GroupBy::None => throw new LogicException('GroupBy::None is handled by renderFlat()'),
            };

            $lines[] = $header;
            $lines[] = '';

            $showFile = $groupBy !== GroupBy::File;

            foreach ($violations as $violation) {
                $this->renderViolation($violation, $color, $context, $lines, showFile: $showFile);
            }
        }
    }

    /**
     * Renders a single violation in compact format (2-3 lines).
     *
     * @param list<string> $lines
     */
    private function renderViolation(
        Violation $violation,
        AnsiColor $color,
        FormatterContext $context,
        array &$lines,
        bool $showFile,
    ): void {
        // Line 1: severity + location + symbol
        $severity = $this->formatSeverityTag($violation->severity, $color);
        $location = $showFile
            ? $this->formatFullLocation($violation, $context)
            : $this->formatLineOnly($violation);
        $symbol = $violation->symbolPath->toString();

        $line1 = \sprintf('  %s %s', $severity, $location);
        if ($symbol !== '') {
            $line1 .= \sprintf('  %s', $color->dim($symbol));
        }
        $lines[] = $line1;

        // Line 2: message + rule code
        $message = $violation->message;
        $metricStr = $this->formatMetricValue($violation, $color);
        $ruleCode = $color->dim('[' . $violation->violationCode . ']');

        $lines[] = \sprintf('    %s%s %s', $message, $metricStr, $ruleCode);
        $lines[] = '';
    }

    private function formatSeverityTag(Severity $severity, AnsiColor $color): string
    {
        return match ($severity) {
            Severity::Error => $color->boldRed('ERROR'),
            Severity::Warning => $color->boldYellow('WARN'),
        };
    }

    private function formatSeverityLabel(string $key, AnsiColor $color): string
    {
        return match ($key) {
            'error' => $color->boldRed('Errors'),
            'warning' => $color->boldYellow('Warnings'),
            default => $key,
        };
    }

    private function formatFullLocation(Violation $violation, FormatterContext $context): string
    {
        $file = $context->relativizePath($violation->location->file);
        $line = $violation->location->line;

        if ($line === null) {
            return $file;
        }

        return \sprintf('%s:%d', $file, $line);
    }

    private function formatLineOnly(Violation $violation): string
    {
        $line = $violation->location->line;

        return $line !== null && $line > 0 ? \sprintf(':%d', $line) : '';
    }

    private function formatMetricValue(Violation $violation, AnsiColor $color): string
    {
        if ($violation->metricValue === null) {
            return '';
        }

        $formatted = \is_float($violation->metricValue)
            ? \sprintf('%.2f', $violation->metricValue)
            : (string) $violation->metricValue;

        return ' ' . $color->bold('(' . $formatted . ')');
    }

    private function formatDebtBreakdown(DebtSummary $debt, Report $report): string
    {
        $lines = [\sprintf('Technical debt: %s', $debt->formatTotal())];

        // Count violations per rule for the breakdown
        /** @var array<string, int> $violationCounts */
        $violationCounts = [];
        foreach ($report->violations as $violation) {
            $violationCounts[$violation->ruleName] = ($violationCounts[$violation->ruleName] ?? 0) + 1;
        }

        // Sort by debt descending
        $perRule = $debt->perRule;
        arsort($perRule);

        foreach ($perRule as $ruleName => $minutes) {
            $count = $violationCounts[$ruleName] ?? 0;
            $perViolation = $count > 0 ? intdiv($minutes, $count) : 0;
            $lines[] = \sprintf(
                '  %s: %s (%d %s × %s)',
                $ruleName,
                DebtSummary::formatMinutes($minutes),
                $count,
                $count === 1 ? 'violation' : 'violations',
                DebtSummary::formatMinutes($perViolation),
            );
        }

        return implode("\n", $lines);
    }

    private function formatSummary(Report $report, AnsiColor $color): string
    {
        $summary = \sprintf(
            'Files: %d analyzed, %d skipped | Errors: %d | Warnings: %d | Time: %.2fs',
            $report->filesAnalyzed,
            $report->filesSkipped,
            $report->errorCount,
            $report->warningCount,
            $report->duration,
        );

        if ($report->errorCount > 0) {
            return $color->boldRed($summary);
        }

        if ($report->warningCount > 0) {
            return $color->boldYellow($summary);
        }

        return $color->boldGreen($summary);
    }
}
