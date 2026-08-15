<?php

declare(strict_types=1);

namespace Qualimetrix\Reporting\Formatter\Support;

use LogicException;
use Qualimetrix\Analysis\Finding\Contract\Severity;
use Qualimetrix\Analysis\Finding\Contract\Violation;
use Qualimetrix\Reporting\FormatterContext;
use Qualimetrix\Reporting\GroupBy;

/** Renders sorted and grouped violation details. */
final class ViolationDetailRenderer
{
    /** @param list<Violation> $violations */
    public function render(array $violations, FormatterContext $context): string
    {
        $color = new AnsiColor($context->useColor);
        $lines = [];
        $effectiveGroupBy = $context->isGroupByExplicit ? $context->groupBy : GroupBy::File;
        $sorted = ViolationSorter::sort($violations, $effectiveGroupBy);

        if ($effectiveGroupBy === GroupBy::None) {
            $this->renderFlat($sorted, $color, $context, $lines);
        } else {
            $this->renderGrouped(
                ViolationSorter::group($sorted, $effectiveGroupBy),
                $effectiveGroupBy,
                $color,
                $context,
                $lines,
            );
        }

        return implode("\n", $lines);
    }

    /**
     * @param list<Violation> $violations
     * @param list<string> $lines
     */
    private function renderFlat(array $violations, AnsiColor $color, FormatterContext $context, array &$lines): void
    {
        foreach ($violations as $violation) {
            $this->renderViolation(
                $violation,
                $color,
                $this->formatFullLocation($violation, $context),
                $lines,
            );
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
            $lines[] = $this->formatGroupHeader($key, $count, $groupBy, $color);

            foreach ($violations as $violation) {
                $location = $groupBy === GroupBy::File
                    ? $this->formatLineOnly($violation)
                    : $this->formatFullLocation($violation, $context);
                $this->renderViolation($violation, $color, $location, $lines);
            }
        }
    }

    private function formatGroupHeader(string $key, int $count, GroupBy $groupBy, AnsiColor $color): string
    {
        return match ($groupBy) {
            GroupBy::File => $this->formatCountedGroupHeader($key, '[project]', $count, $color),
            GroupBy::Rule => \sprintf('%s (%d)', $color->bold($this->nonEmptyKey($key, '<unknown>')), $count),
            GroupBy::Severity => \sprintf('%s (%d)', $this->formatSeverityLabel($key, $color), $count),
            GroupBy::ClassName => $this->formatCountedGroupHeader($key, '<unknown>', $count, $color),
            GroupBy::NamespaceName => $this->formatCountedGroupHeader($key, '<global>', $count, $color),
            GroupBy::None => throw new LogicException('GroupBy::None is handled by renderFlat()'),
        };
    }

    private function formatCountedGroupHeader(
        string $key,
        string $fallback,
        int $count,
        AnsiColor $color,
    ): string {
        return \sprintf(
            '%s (%d %s)',
            $color->bold($this->nonEmptyKey($key, $fallback)),
            $count,
            $count === 1 ? 'violation' : 'violations',
        );
    }

    private function nonEmptyKey(string $key, string $fallback): string
    {
        return $key !== '' ? $key : $fallback;
    }

    /** @param list<string> $lines */
    private function renderViolation(
        Violation $violation,
        AnsiColor $color,
        string $location,
        array &$lines,
    ): void {
        $severity = $this->formatSeverityTag($violation->severity, $color);
        $symbol = $violation->symbolPath->getSymbolName();

        $line = '  ' . $severity;
        if ($location !== '') {
            $line .= ' ' . $location;
        }
        if ($symbol !== null && $symbol !== '') {
            $line .= '  ' . $symbol;
        }
        $lines[] = $line;

        $message = $violation->getDisplayMessage() . $this->formatBreachSuffix($violation);
        $ruleCode = $color->dim('[' . $violation->violationCode . ']');
        $lines[] = \sprintf('    %s  %s', $message, $ruleCode);
        $lines[] = '';
    }

    private function formatSeverityTag(Severity $severity, AnsiColor $color): string
    {
        return match ($severity) {
            Severity::Error => $color->boldRed('ERROR'),
            Severity::Warning => $color->boldYellow('WARN'),
            Severity::Info => $color->boldCyan('INFO'),
        };
    }

    private function formatSeverityLabel(string $key, AnsiColor $color): string
    {
        return match ($key) {
            'error' => $color->boldRed('Errors'),
            'warning' => $color->boldYellow('Warnings'),
            'info' => $color->boldCyan('Info'),
            default => $key,
        };
    }

    private function formatFullLocation(Violation $violation, FormatterContext $context): string
    {
        if ($violation->location->file === null) {
            return '[project]';
        }

        $file = $context->relativizePath($violation->location->file);
        $line = $violation->location->line;

        return $line === null || !$violation->location->precise ? $file : \sprintf('%s:%d', $file, $line);
    }

    private function formatLineOnly(Violation $violation): string
    {
        $line = $violation->location->line;

        return $line !== null && $violation->location->precise ? \sprintf('at line %d', $line) : '';
    }

    private function formatBreachSuffix(Violation $violation): string
    {
        $breach = AcceptedLevelNarrator::describe($violation);

        return $breach === null ? '' : \sprintf(' (%s)', $breach);
    }
}
