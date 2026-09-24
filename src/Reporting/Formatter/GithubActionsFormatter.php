<?php

declare(strict_types=1);

namespace Qualimetrix\Reporting\Formatter;

use Qualimetrix\Analysis\Finding\Contract\Finding;
use Qualimetrix\Analysis\Finding\Contract\Severity;
use Qualimetrix\Reporting\DrillDown\OutOfScopeFindings;
use Qualimetrix\Reporting\FormatterContext;
use Qualimetrix\Reporting\GroupBy;
use Qualimetrix\Reporting\Report;
use Qualimetrix\Reporting\ReportProjectScope;

/**
 * Formats report as GitHub Actions workflow commands.
 *
 * Produces inline annotations that appear directly in PR diffs
 * when running inside GitHub Actions CI.
 *
 * @see https://docs.github.com/en/actions/writing-workflows/choosing-what-your-workflow-does/workflow-commands-for-github-actions#setting-a-warning-message
 *
 * @qmx-ignore health.cohesion -- Stateless: no instance field for TCC to measure, so from the sixth
 *             counted method the score reads an undefined TCC as zero; the escaping and notice
 *             helpers share no state by design.
 */
final class GithubActionsFormatter implements FormatterInterface
{
    public function format(Report $report, FormatterContext $context): string
    {
        $lines = [];

        foreach ($report->coverage === null ? [] : $report->coverage->failures as $failure) {
            $lines[] = \sprintf(
                '::error file=%s,line=1,title=analysis.%s::%s',
                $this->escapeProperty($failure->path),
                $this->escapeProperty($failure->kind),
                $this->escapeData($failure->message),
            );
        }

        foreach ($report->findings as $finding) {
            $lines[] = $this->formatFinding($finding, $context);
        }

        foreach (self::notices($report) as $title => $notice) {
            $lines[] = \sprintf('::notice title=%s::%s', $title, $this->escapeData($notice));
        }

        if ($lines === [] && ($report->coverage === null || $report->coverage->isComplete())) {
            return '';
        }

        return implode("\n", $lines) . "\n";
    }

    public function getName(): string
    {
        return 'github';
    }

    /**
     * What the report says about itself rather than about the code, by title.
     *
     * @return array<string, string>
     */
    private static function notices(Report $report): array
    {
        $outOfScope = $report->outOfScope;

        return array_filter([
            OutOfScopeFindings::CHECK => $outOfScope !== null && $outOfScope->total() > 0 ? $outOfScope->describe() : null,
            ReportProjectScope::CHECK => $report->projectScope?->describe(),
        ], is_string(...));
    }

    public function getDefaultGroupBy(): GroupBy
    {
        return GroupBy::None;
    }

    private function formatFinding(Finding $finding, FormatterContext $context): string
    {
        $command = $this->severityToCommand($finding->severity);

        $params = [];

        if ($finding->location->file !== null) {
            $params[] = 'file=' . $this->escapeProperty($context->relativizePath($finding->location->file));

            if ($finding->location->line !== null) {
                $params[] = 'line=' . $finding->location->line;
            }
        }

        $params[] = 'title=' . $this->escapeProperty($finding->code);

        return \sprintf(
            '::%s %s::%s',
            $command,
            implode(',', $params),
            $this->escapeData(PublishedFinding::annotatedMessage($finding)),
        );
    }

    private function severityToCommand(Severity $severity): string
    {
        return match ($severity) {
            Severity::Error => 'error',
            Severity::Warning => 'warning',
            // GitHub Actions has no "info" annotation level; "notice" is the
            // closest standard equivalent and renders as a neutral info badge.
            Severity::Info => 'notice',
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
