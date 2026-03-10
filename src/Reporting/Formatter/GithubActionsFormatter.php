<?php

declare(strict_types=1);

namespace AiMessDetector\Reporting\Formatter;

use AiMessDetector\Core\Violation\Severity;
use AiMessDetector\Core\Violation\Violation;
use AiMessDetector\Reporting\FormatterContext;
use AiMessDetector\Reporting\GroupBy;
use AiMessDetector\Reporting\Report;

/**
 * Formats report as GitHub Actions workflow commands.
 *
 * Produces inline annotations that appear directly in PR diffs
 * when running inside GitHub Actions CI.
 *
 * @see https://docs.github.com/en/actions/writing-workflows/choosing-what-your-workflow-does/workflow-commands-for-github-actions#setting-a-warning-message
 */
final class GithubActionsFormatter implements FormatterInterface
{
    public function format(Report $report, FormatterContext $context): string
    {
        if ($report->isEmpty()) {
            return '';
        }

        $lines = [];

        foreach ($report->violations as $violation) {
            $lines[] = $this->formatViolation($violation, $context);
        }

        return implode("\n", $lines) . "\n";
    }

    public function getName(): string
    {
        return 'github';
    }

    public function getDefaultGroupBy(): GroupBy
    {
        return GroupBy::None;
    }

    private function formatViolation(Violation $violation, FormatterContext $context): string
    {
        $command = $this->severityToCommand($violation->severity);

        $params = [];

        if (!$violation->location->isNone()) {
            $params[] = 'file=' . $this->escapeProperty($context->relativizePath($violation->location->file));

            if ($violation->location->line !== null) {
                $params[] = 'line=' . $violation->location->line;
            }
        }

        $params[] = 'title=' . $this->escapeProperty($violation->violationCode);

        return \sprintf(
            '::%s %s::%s',
            $command,
            implode(',', $params),
            $this->escapeData($violation->message),
        );
    }

    private function severityToCommand(Severity $severity): string
    {
        return match ($severity) {
            Severity::Error => 'error',
            Severity::Warning => 'warning',
        };
    }

    /**
     * Escapes property values (file, title) per GitHub Actions workflow command spec.
     *
     * @see https://github.com/actions/toolkit/blob/main/packages/core/src/command.ts (escapeProperty)
     */
    private function escapeProperty(string $value): string
    {
        return str_replace(
            ['%', "\r", "\n", ':', ','],
            ['%25', '%0D', '%0A', '%3A', '%2C'],
            $value,
        );
    }

    /**
     * Escapes message data per GitHub Actions workflow command spec.
     *
     * @see https://github.com/actions/toolkit/blob/main/packages/core/src/command.ts (escapeData)
     */
    private function escapeData(string $message): string
    {
        return str_replace(
            ['%', "\r", "\n"],
            ['%25', '%0D', '%0A'],
            $message,
        );
    }
}
