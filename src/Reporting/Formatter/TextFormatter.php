<?php

declare(strict_types=1);

namespace AiMessDetector\Reporting\Formatter;

use AiMessDetector\Core\Symbol\SymbolType;
use AiMessDetector\Core\Violation\Severity;
use AiMessDetector\Core\Violation\Violation;
use AiMessDetector\Reporting\Debt\DebtCalculator;
use AiMessDetector\Reporting\Formatter\Support\AnsiColor;
use AiMessDetector\Reporting\Formatter\Support\DetailedViolationRenderer;
use AiMessDetector\Reporting\Formatter\Support\ViolationSorter;
use AiMessDetector\Reporting\FormatterContext;
use AiMessDetector\Reporting\GroupBy;
use AiMessDetector\Reporting\Report;

/**
 * Formats report as compact, parseable text output (one line per violation).
 *
 * Output format: file:line: severity[violationCode]: message (symbol)
 *
 * This format is:
 * - Compatible with GCC/Clang error format
 * - Parseable by grep, awk, cut and similar tools
 * - Clickable in IDEs and terminals
 *
 * With --detail: switches to grouped, human-readable output with debt breakdown.
 */
final class TextFormatter implements FormatterInterface
{
    public function __construct(
        private readonly DebtCalculator $debtCalculator,
        private readonly DetailedViolationRenderer $detailedRenderer,
    ) {}

    public function format(Report $report, FormatterContext $context): string
    {
        if ($context->isDetailEnabled()) {
            return $this->formatDetailed($report, $context);
        }

        return $this->formatFlat($report, $context);
    }

    public function getName(): string
    {
        return 'text';
    }

    public function getDefaultGroupBy(): GroupBy
    {
        return GroupBy::None;
    }

    private function formatFlat(Report $report, FormatterContext $context): string
    {
        $color = new AnsiColor($context->useColor);
        $sorted = ViolationSorter::sort($report->violations, $context->groupBy);

        $lines = [];

        foreach ($sorted as $violation) {
            $lines[] = $this->formatViolation($violation, $color, $context);
        }

        // Summary line at the end
        if ($lines !== []) {
            $lines[] = '';
        }
        $lines[] = $this->formatSummary($report, $color);

        // Technical debt line (dimmed to visually distinguish from summary)
        $debt = $this->debtCalculator->calculate($report->violations);
        $lines[] = $color->dim(\sprintf('Technical debt: %s', $debt->formatTotal()));

        return implode("\n", $lines) . "\n";
    }

    private function formatDetailed(Report $report, FormatterContext $context): string
    {
        $violations = $report->violations;
        $limit = $context->detailLimit;
        $totalCount = \count($violations);
        $showAll = $limit === null || $limit === 0 || $totalCount <= $limit;
        $displayViolations = $showAll ? $violations : \array_slice($violations, 0, $limit);

        $color = new AnsiColor($context->useColor);
        $lines = [];

        // Detailed violation list
        $lines[] = $this->detailedRenderer->render($displayViolations, $context);

        if (!$showAll) {
            $remaining = $totalCount - $limit;
            $lines[] = '';
            $lines[] = $color->dim(\sprintf(
                '... and %d more. Use --detail=all to see all violations',
                $remaining,
            ));
        }

        $lines[] = '';

        // Summary line
        $lines[] = $this->formatSummary($report, $color);

        return implode("\n", $lines) . "\n";
    }

    private function formatViolation(Violation $violation, AnsiColor $color, FormatterContext $context): string
    {
        $file = $violation->location->isNone() ? '[project]' : $context->relativizePath($violation->location->file);
        $line = $violation->location->line;
        $severity = $this->formatSeverity($violation->severity, $color);
        $rule = $color->dim($violation->violationCode);
        $message = $violation->message;
        $symbol = $this->formatSymbol($violation);

        // Format: file:line: severity[rule]: message (symbol)
        $location = $line !== null && $violation->location->precise ? "{$file}:{$line}" : $file;

        return \sprintf('%s: %s[%s]: %s%s', $location, $severity, $rule, $message, $symbol);
    }

    private function formatSeverity(Severity $severity, AnsiColor $color): string
    {
        return match ($severity) {
            Severity::Error => $color->red('error'),
            Severity::Warning => $color->yellow('warning'),
        };
    }

    private function formatSymbol(Violation $violation): string
    {
        $symbol = $violation->symbolPath->getSymbolName();

        if ($symbol !== null && $symbol !== '') {
            return " ({$symbol})";
        }

        if ($violation->symbolPath->getType() === SymbolType::Namespace_) {
            $namespace = $violation->symbolPath->toString();

            return $namespace !== '' ? \sprintf(' (namespace: %s)', $namespace) : '';
        }

        return '';
    }

    private function formatSummary(Report $report, AnsiColor $color): string
    {
        $summary = \sprintf(
            '%d error(s), %d warning(s) in %d file(s)',
            $report->errorCount,
            $report->warningCount,
            $report->filesAnalyzed,
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
