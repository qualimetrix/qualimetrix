<?php

declare(strict_types=1);

namespace Qualimetrix\Reporting\Formatter\Summary;

use Qualimetrix\Analysis\Evidence\Prioritization\Debt\DebtSummary;
use Qualimetrix\Analysis\Evidence\Prioritization\Impact\RankedIssue;
use Qualimetrix\Analysis\Finding\Contract\Finding;
use Qualimetrix\Analysis\Finding\Contract\Severity;
use Qualimetrix\Core\Symbol\SymbolType;
use Qualimetrix\Reporting\Formatter\Support\AnsiColor;
use Qualimetrix\Reporting\FormatterContext;
use Qualimetrix\Reporting\Report;

/**
 * Renders the "Top issues by impact" section in the summary output.
 *
 * Shows a ranked list of findings prioritized by impact score,
 * which combines ClassRank, severity, and remediation time.
 */
final class TopIssuesRenderer
{
    /**
     * Renders the top issues section and appends lines to the output buffer.
     *
     * Skipped when there are no top issues or when topIssuesLimit is 0.
     *
     * @param list<string> $lines
     */
    public function render(Report $report, FormatterContext $context, AnsiColor $color, array &$lines): void
    {
        if ($report->topIssues === [] || $context->topIssuesLimit === 0) {
            return;
        }

        $filtered = $this->filterByContext($report->topIssues, $context);

        if ($filtered === []) {
            return;
        }

        $issues = \array_slice($filtered, 0, $context->topIssuesLimit);

        $lines[] = '';
        $lines[] = $color->bold('Top issues by impact');

        foreach ($issues as $rank => $issue) {
            $this->renderIssue($rank + 1, $issue, $context, $color, $lines);
        }
    }

    /**
     * @param list<string> $lines
     */
    private function renderIssue(int $rank, RankedIssue $issue, FormatterContext $context, AnsiColor $color, array &$lines): void
    {
        $finding = $issue->finding;
        $severity = match ($finding->severity) {
            Severity::Error => 'ERR',
            Severity::Warning => 'WRN',
            Severity::Info => 'INF',
        };
        $severityFormatted = match ($finding->severity) {
            Severity::Error => $color->red($severity),
            Severity::Warning => $color->yellow($severity),
            Severity::Info => $color->cyan($severity),
        };

        $score = $this->formatScore($issue->impactScore);
        $debt = DebtSummary::formatMinutes($issue->debtMinutes);

        $locationStr = $this->formatLocation($finding, $context);

        $detail = \sprintf('%s: %s%s', $finding->code, $finding->getDisplayMessage(), $this->formatSymbol($finding));

        $indent = str_repeat(' ', \strlen((string) $rank) + 8);

        $lines[] = \sprintf(
            '  %s. [%s] %s  %s  %s',
            $color->bold((string) $rank),
            $severityFormatted,
            $color->bold($score),
            $locationStr,
            $color->dim(\sprintf('[%s]', $debt)),
        );
        $lines[] = \sprintf(
            '%s%s',
            $indent,
            $color->dim($detail),
        );
    }

    private function formatLocation(Finding $finding, FormatterContext $context): string
    {
        if ($finding->location->file === null) {
            return '[project]';
        }

        $file = $context->relativizePath($finding->location->file);
        $line = $finding->location->line;

        return $line !== null && $finding->location->precise
            ? \sprintf('%s:%d', $file, $line)
            : $file;
    }

    private function formatSymbol(Finding $finding): string
    {
        $symbolPath = $finding->symbolPath;
        $type = $symbolPath->getType();

        if ($type === SymbolType::Method || $type === SymbolType::Function_) {
            $symbolName = $symbolPath->getSymbolName();

            return $symbolName !== null && $symbolName !== ''
                ? \sprintf(' (%s)', $symbolName)
                : '';
        }

        if ($type === SymbolType::Namespace_) {
            $namespace = $symbolPath->toString();

            return $namespace !== '' ? \sprintf(' (namespace: %s)', $namespace) : '';
        }

        return '';
    }

    /**
     * Filters top issues by namespace/class drill-down context.
     *
     * @param list<RankedIssue> $issues
     *
     * @return list<RankedIssue>
     */
    private function filterByContext(array $issues, FormatterContext $context): array
    {
        if ($context->namespace === null && $context->class === null) {
            return $issues;
        }

        return array_values(array_filter($issues, static function (RankedIssue $issue) use ($context): bool {
            $sp = $issue->finding->symbolPath;
            $ns = $sp->namespace ?? '';
            $type = $sp->type;

            if ($context->namespace !== null) {
                return $ns === $context->namespace || str_starts_with($ns, $context->namespace . '\\');
            }

            if ($context->class !== null && $type !== null) {
                $fqcn = $ns !== '' ? $ns . '\\' . $type : $type;

                return $fqcn === $context->class;
            }

            return false;
        }));
    }

    private function formatScore(float $score): string
    {
        if ($score >= 100) {
            return \sprintf('%.0f', $score);
        }

        if ($score >= 10) {
            return \sprintf('%.1f', $score);
        }

        return \sprintf('%.2f', $score);
    }
}
