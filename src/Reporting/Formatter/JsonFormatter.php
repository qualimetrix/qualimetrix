<?php

declare(strict_types=1);

namespace AiMessDetector\Reporting\Formatter;

use AiMessDetector\Core\Violation\Severity;
use AiMessDetector\Core\Violation\Violation;
use AiMessDetector\Reporting\FormatterContext;
use AiMessDetector\Reporting\GroupBy;
use AiMessDetector\Reporting\Report;

/**
 * Formats report as JSON output.
 *
 * Compatible with PHPMD JSON output format for CI/CD integration.
 */
final class JsonFormatter implements FormatterInterface
{
    private const VERSION = '1.0.0';
    private const PACKAGE = 'aimd';

    public function format(Report $report, FormatterContext $context): string
    {
        $files = $this->groupViolationsByFile($report->violations, $context);

        $data = [
            'version' => self::VERSION,
            'package' => self::PACKAGE,
            'timestamp' => gmdate('c'),
            'files' => $files,
            'summary' => [
                'filesAnalyzed' => $report->filesAnalyzed,
                'filesSkipped' => $report->filesSkipped,
                'violations' => $report->getTotalViolations(),
                'errors' => $report->errorCount,
                'warnings' => $report->warningCount,
                'duration' => round($report->duration, 3),
            ],
        ];

        $json = json_encode($data, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR);

        return $json;
    }

    public function getName(): string
    {
        return 'json';
    }

    public function getDefaultGroupBy(): GroupBy
    {
        return GroupBy::None;
    }

    /**
     * Groups violations by file path for PHPMD-compatible output.
     *
     * @param list<Violation> $violations
     *
     * @return list<array{file: string|null, violations: list<array{beginLine: int|null, endLine: int|null, rule: string, code: string, symbol: string, priority: int, severity: string, description: string, metricValue: int|float|null}>}>
     */
    private function groupViolationsByFile(array $violations, FormatterContext $context): array
    {
        /** @var array<string, list<Violation>> $grouped */
        $grouped = [];

        foreach ($violations as $violation) {
            $file = $violation->location->isNone() ? '' : $context->relativizePath($violation->location->file);
            $grouped[$file] ??= [];
            $grouped[$file][] = $violation;
        }

        $result = [];
        foreach ($grouped as $file => $fileViolations) {
            $result[] = [
                'file' => $file !== '' ? $file : null,
                'violations' => array_map(
                    fn(Violation $v): array => $this->formatViolation($v),
                    $fileViolations,
                ),
            ];
        }

        return $result;
    }

    /**
     * Formats a single violation for JSON output.
     *
     * @return array{beginLine: int|null, endLine: int|null, rule: string, code: string, symbol: string, priority: int, severity: string, description: string, metricValue: int|float|null}
     */
    private function formatViolation(Violation $violation): array
    {
        return [
            'beginLine' => $violation->location->line,
            'endLine' => $violation->location->line,
            'rule' => $violation->ruleName,
            'code' => $violation->violationCode,
            'symbol' => $violation->symbolPath->toString(),
            'priority' => $this->severityToPriority($violation->severity),
            'severity' => $this->severityToString($violation->severity),
            'description' => $violation->message,
            'metricValue' => $violation->metricValue,
        ];
    }

    /**
     * Converts severity to PHPMD-style priority (1-5, lower = more severe).
     */
    private function severityToPriority(Severity $severity): int
    {
        return match ($severity) {
            Severity::Error => 1,
            Severity::Warning => 3,
        };
    }

    private function severityToString(Severity $severity): string
    {
        return match ($severity) {
            Severity::Error => 'error',
            Severity::Warning => 'warning',
        };
    }
}
