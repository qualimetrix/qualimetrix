<?php

declare(strict_types=1);

namespace Qualimetrix\Reporting\Formatter\Support;

use LogicException;
use Qualimetrix\Analysis\Finding\Contract\Finding;
use Qualimetrix\Analysis\Finding\Contract\Severity;
use Qualimetrix\Reporting\FormatterContext;
use Qualimetrix\Reporting\GroupBy;

/** Renders sorted and grouped finding details. */
final class FindingDetailRenderer
{
    /** @param list<Finding> $findings */
    public function render(array $findings, FormatterContext $context): string
    {
        $color = new AnsiColor($context->useColor);
        $lines = [];
        $effectiveGroupBy = $context->isGroupByExplicit ? $context->groupBy : GroupBy::File;
        $sorted = FindingSorter::sort($findings, $effectiveGroupBy);

        if ($effectiveGroupBy === GroupBy::None) {
            $this->renderFlat($sorted, $color, $context, $lines);
        } else {
            $this->renderGrouped(
                FindingSorter::group($sorted, $effectiveGroupBy),
                $effectiveGroupBy,
                $color,
                $context,
                $lines,
            );
        }

        return implode("\n", $lines);
    }

    /**
     * @param list<Finding> $findings
     * @param list<string> $lines
     */
    private function renderFlat(array $findings, AnsiColor $color, FormatterContext $context, array &$lines): void
    {
        foreach ($findings as $finding) {
            $this->renderFinding(
                $finding,
                $color,
                $this->formatFullLocation($finding, $context),
                $lines,
            );
        }
    }

    /**
     * @param array<string, list<Finding>> $groups
     * @param list<string> $lines
     */
    private function renderGrouped(
        array $groups,
        GroupBy $groupBy,
        AnsiColor $color,
        FormatterContext $context,
        array &$lines,
    ): void {
        foreach ($groups as $key => $findings) {
            $count = \count($findings);
            $lines[] = $this->formatGroupHeader($key, $count, $groupBy, $color);

            foreach ($findings as $finding) {
                $location = $groupBy === GroupBy::File
                    ? $this->formatLineOnly($finding)
                    : $this->formatFullLocation($finding, $context);
                $this->renderFinding($finding, $color, $location, $lines);
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
    private function renderFinding(
        Finding $finding,
        AnsiColor $color,
        string $location,
        array &$lines,
    ): void {
        $severity = $this->formatSeverityTag($finding->severity, $color);
        $symbol = $finding->symbolPath->getSymbolName();

        $line = '  ' . $severity;
        if ($location !== '') {
            $line .= ' ' . $location;
        }
        if ($symbol !== null && $symbol !== '') {
            $line .= '  ' . $symbol;
        }
        $lines[] = $line;

        $message = $finding->getDisplayMessage() . $this->formatBreachSuffix($finding);
        $ruleCode = $color->dim('[' . $finding->code . ']');
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

    private function formatFullLocation(Finding $finding, FormatterContext $context): string
    {
        if ($finding->location->file === null) {
            return '[project]';
        }

        $file = $context->relativizePath($finding->location->file);
        $line = $finding->location->line;

        return $line === null || !$finding->location->precise ? $file : \sprintf('%s:%d', $file, $line);
    }

    private function formatLineOnly(Finding $finding): string
    {
        $line = $finding->location->line;

        return $line !== null && $finding->location->precise ? \sprintf('at line %d', $line) : '';
    }

    private function formatBreachSuffix(Finding $finding): string
    {
        $breach = AcceptedLevelNarrator::describe($finding);

        return $breach === null ? '' : \sprintf(' (%s)', $breach);
    }
}
