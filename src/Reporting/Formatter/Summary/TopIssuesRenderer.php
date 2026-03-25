<?php

declare(strict_types=1);

namespace Qualimetrix\Reporting\Formatter\Summary;

use Qualimetrix\Reporting\Debt\DebtSummary;
use Qualimetrix\Reporting\Formatter\Support\AnsiColor;
use Qualimetrix\Reporting\FormatterContext;
use Qualimetrix\Reporting\Impact\RankedIssue;
use Qualimetrix\Reporting\Report;

/**
 * Renders the "Top issues by impact" section in the summary output.
 *
 * Shows a ranked list of violations prioritized by impact score,
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
        $violation = $issue->violation;
        $severity = $violation->severity->value === 'error' ? 'ERR' : 'WRN';
        $severityFormatted = $violation->severity->value === 'error'
            ? $color->red($severity)
            : $color->yellow($severity);

        $score = $this->formatScore($issue->impactScore);
        $symbol = $violation->symbolPath->toString();
        $debt = DebtSummary::formatMinutes($issue->debtMinutes);

        $file = $context->relativizePath($violation->location->file);
        $line = $violation->location->line;
        $locationStr = $line !== null ? \sprintf('%s:%d', $file, $line) : $file;

        $lines[] = \sprintf(
            '  %s. [%s] %s  %s',
            $color->bold((string) $rank),
            $severityFormatted,
            $color->bold($score),
            $symbol,
        );
        $lines[] = \sprintf(
            '         %s  %s',
            $color->dim($locationStr),
            $color->dim(\sprintf('[%s]', $debt)),
        );
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
            $sp = $issue->violation->symbolPath;
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
