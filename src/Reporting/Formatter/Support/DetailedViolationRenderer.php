<?php

declare(strict_types=1);

namespace Qualimetrix\Reporting\Formatter\Support;

use LogicException;
use Qualimetrix\Core\Violation\Severity;
use Qualimetrix\Core\Violation\Violation;
use Qualimetrix\Reporting\Debt\DebtCalculator;
use Qualimetrix\Reporting\Debt\DebtSummary;
use Qualimetrix\Reporting\FormatterContext;
use Qualimetrix\Reporting\GroupBy;

/**
 * Renders detailed violation output shared by TextFormatter (--detail) and SummaryFormatter (--detail).
 *
 * Groups violations by file/rule/severity, shows recommendation with metric context,
 * and appends a technical debt breakdown by rule.
 */
final class DetailedViolationRenderer
{
    public function __construct(
        private readonly DebtCalculator $debtCalculator,
    ) {}

    /**
     * Renders detailed violation output.
     *
     * Note: if $violations is empty, returns early with "no violations" message
     * regardless of $allViolations content. Callers should not pass empty $violations
     * with non-empty $allViolations.
     *
     * @param list<Violation> $violations Violations to display (may be truncated by --detail limit)
     * @param list<Violation>|null $allViolations Full violation list for debt calculation (defaults to $violations)
     *
     * @return string Formatted detail block (without trailing newline)
     */
    public function render(array $violations, FormatterContext $context, ?array $allViolations = null): string
    {
        $color = new AnsiColor($context->useColor);
        $lines = [];

        if ($violations === []) {
            $label = $context->namespace !== null || $context->class !== null
                ? 'No violations in this scope.'
                : 'No violations found.';
            $lines[] = $color->boldGreen($label);

            return implode("\n", $lines);
        }

        // Determine effective grouping: default to File in detail mode unless explicit
        $effectiveGroupBy = $context->isGroupByExplicit
            ? $context->groupBy
            : GroupBy::File;

        $sorted = ViolationSorter::sort($violations, $effectiveGroupBy);

        if ($effectiveGroupBy === GroupBy::None) {
            $this->renderFlat($sorted, $color, $context, $lines);
        } else {
            $groups = ViolationSorter::group($sorted, $effectiveGroupBy);
            $this->renderGrouped($groups, $effectiveGroupBy, $color, $context, $lines);
        }

        // Debt breakdown by rule (always use full violation list for accurate totals)
        $debtViolations = $allViolations ?? $violations;
        $debt = $this->debtCalculator->calculate($debtViolations);
        $lines[] = $this->renderDebtBreakdown($debt, $debtViolations);

        return implode("\n", $lines);
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
    private function renderGrouped(
        array $groups,
        GroupBy $groupBy,
        AnsiColor $color,
        FormatterContext $context,
        array &$lines,
    ): void {
        foreach ($groups as $key => $violations) {
            $count = \count($violations);
            $header = match ($groupBy) {
                GroupBy::File => \sprintf(
                    '%s (%d %s)',
                    $color->bold($key !== '' ? $context->relativizePath($key) : '[project]'),
                    $count,
                    $count === 1 ? 'violation' : 'violations',
                ),
                GroupBy::Rule => \sprintf('%s (%d)', $color->bold($key !== '' ? $key : '<unknown>'), $count),
                GroupBy::Severity => \sprintf('%s (%d)', $this->formatSeverityLabel($key, $color), $count),
                GroupBy::ClassName => \sprintf(
                    '%s (%d %s)',
                    $color->bold($key !== '' ? $key : '<unknown>'),
                    $count,
                    $count === 1 ? 'violation' : 'violations',
                ),
                GroupBy::NamespaceName => \sprintf(
                    '%s (%d %s)',
                    $color->bold($key !== '' ? $key : '<global>'),
                    $count,
                    $count === 1 ? 'violation' : 'violations',
                ),
                GroupBy::None => throw new LogicException('GroupBy::None is handled by renderFlat()'),
            };

            $lines[] = $header;

            $showFile = $groupBy !== GroupBy::File;

            foreach ($violations as $violation) {
                $this->renderViolation($violation, $color, $context, $lines, showFile: $showFile);
            }
        }
    }

    /**
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
        $symbol = $violation->symbolPath->getSymbolName();

        $line1 = '  ' . $severity;
        if ($location !== '') {
            $line1 .= ' ' . $location;
        }
        if ($symbol !== null && $symbol !== '') {
            $line1 .= '  ' . $symbol;
        }
        $lines[] = $line1;

        // Line 2: human message + rule code
        $message = $violation->getDisplayMessage();
        $ruleCode = $color->dim('[' . $violation->violationCode . ']');
        $lines[] = \sprintf('    %s  %s', $message, $ruleCode);
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
        if ($violation->location->isNone()) {
            return '[project]';
        }

        $file = $context->relativizePath($violation->location->file);
        $line = $violation->location->line;

        if ($line === null || !$violation->location->precise) {
            return $file;
        }

        return \sprintf('%s:%d', $file, $line);
    }

    private function formatLineOnly(Violation $violation): string
    {
        $line = $violation->location->line;

        return $line !== null && $violation->location->precise ? \sprintf('at line %d', $line) : '';
    }

    /**
     * @param list<Violation> $violations
     */
    private function renderDebtBreakdown(DebtSummary $debt, array $violations): string
    {
        if ($debt->perRule === []) {
            return '';
        }

        $lines = [];
        $lines[] = 'Technical debt by rule:';

        // Count violations per rule
        /** @var array<string, int> $violationCounts */
        $violationCounts = [];
        foreach ($violations as $violation) {
            $violationCounts[$violation->ruleName] = ($violationCounts[$violation->ruleName] ?? 0) + 1;
        }

        // Sort by debt descending
        $perRule = $debt->perRule;
        arsort($perRule);

        foreach ($perRule as $ruleName => $minutes) {
            $count = $violationCounts[$ruleName] ?? 0;
            $lines[] = \sprintf(
                '  %-40s ~%-8s (%d %s)',
                $ruleName,
                DebtSummary::formatMinutes($minutes),
                $count,
                $count === 1 ? 'violation' : 'violations',
            );
        }

        return implode("\n", $lines);
    }
}
