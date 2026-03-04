<?php

declare(strict_types=1);

namespace AiMessDetector\Reporting\Formatter;

use AiMessDetector\Core\Symbol\SymbolType;
use AiMessDetector\Core\Violation\Severity;
use AiMessDetector\Core\Violation\Violation;
use AiMessDetector\Reporting\Report;

/**
 * Formats report as compact, parseable text output (one line per violation).
 *
 * Output format: file:line: severity[rule]: message (symbol)
 *
 * This format is:
 * - Compatible with GCC/Clang error format
 * - Parseable by grep, awk, cut and similar tools
 * - Clickable in IDEs and terminals
 *
 * Use --format=text-verbose for human-readable multi-line output.
 */
final class TextFormatter implements FormatterInterface
{
    public function format(Report $report): string
    {
        $lines = [];

        foreach ($report->violations as $violation) {
            $lines[] = $this->formatViolation($violation);
        }

        // Summary line at the end
        if ($lines !== []) {
            $lines[] = '';
        }
        $lines[] = $this->formatSummary($report);

        return implode("\n", $lines) . "\n";
    }

    public function getName(): string
    {
        return 'text';
    }

    private function formatViolation(Violation $violation): string
    {
        $file = $violation->location->file;
        $line = $violation->location->line;
        $severity = $this->formatSeverity($violation->severity);
        $rule = $violation->ruleName;
        $message = $violation->message;
        $symbol = $this->formatSymbol($violation);

        // Format: file:line: severity[rule]: message (symbol)
        $location = $line !== null ? "{$file}:{$line}" : $file;

        return \sprintf('%s: %s[%s]: %s%s', $location, $severity, $rule, $message, $symbol);
    }

    private function formatSeverity(Severity $severity): string
    {
        return match ($severity) {
            Severity::Error => 'error',
            Severity::Warning => 'warning',
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

    private function formatSummary(Report $report): string
    {
        return \sprintf(
            '%d error(s), %d warning(s) in %d file(s)',
            $report->errorCount,
            $report->warningCount,
            $report->filesAnalyzed,
        );
    }
}
