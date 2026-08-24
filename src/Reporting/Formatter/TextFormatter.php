<?php

declare(strict_types=1);

namespace Qualimetrix\Reporting\Formatter;

use Qualimetrix\Analysis\Evidence\Prioritization\Debt\DebtCalculator;
use Qualimetrix\Analysis\Finding\Contract\Finding;
use Qualimetrix\Analysis\Finding\Contract\Severity;
use Qualimetrix\Core\Symbol\SymbolType;
use Qualimetrix\Core\Version;
use Qualimetrix\Reporting\Formatter\Support\AcceptedLevelNarrator;
use Qualimetrix\Reporting\Formatter\Support\AnsiColor;
use Qualimetrix\Reporting\Formatter\Support\CoverageNarrator;
use Qualimetrix\Reporting\Formatter\Support\DetailedFindingRenderer;
use Qualimetrix\Reporting\Formatter\Support\FindingSorter;
use Qualimetrix\Reporting\FormatterContext;
use Qualimetrix\Reporting\GroupBy;
use Qualimetrix\Reporting\Report;

/**
 * Formats report as compact, parseable text output (one line per finding).
 *
 * Output format: file:line: severity[code]: message (symbol)
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
        private readonly DetailedFindingRenderer $detailedRenderer,
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
        $sorted = FindingSorter::sort($report->findings, $context->groupBy);

        $lines = [];

        foreach ($sorted as $finding) {
            $lines[] = $this->formatFinding($finding, $color, $context);
        }

        // Summary line at the end
        if ($lines !== []) {
            $lines[] = '';
        }
        $lines[] = $this->formatSummary($report, $color);
        if ($report->coverage !== null) {
            $lines[] = CoverageNarrator::describe($report->coverage);
        }

        // Technical debt line (dimmed to visually distinguish from summary)
        $debt = $this->debtCalculator->calculate($report->findings);
        $lines[] = $color->dim(\sprintf('Technical debt: %s', $debt->formatTotal()));

        return implode("\n", $lines) . "\n";
    }

    private function formatDetailed(Report $report, FormatterContext $context): string
    {
        $findings = $report->findings;
        $limit = $context->detailLimit;
        $totalCount = \count($findings);
        $showAll = $limit === null || $limit === 0 || $totalCount <= $limit;
        $displayFindings = $showAll ? $findings : \array_slice($findings, 0, $limit);

        $color = new AnsiColor($context->useColor);
        $lines = [];

        // Detailed finding list
        $lines[] = $this->detailedRenderer->render($displayFindings, $context, $findings);

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
        if ($report->coverage !== null) {
            $lines[] = CoverageNarrator::describe($report->coverage);
        }

        return implode("\n", $lines) . "\n";
    }

    private function formatFinding(Finding $finding, AnsiColor $color, FormatterContext $context): string
    {
        $file = $finding->location->file === null
            ? '[project]'
            : $context->relativizePath($finding->location->file);
        $line = $finding->location->line;
        $severity = $this->formatSeverity($finding->severity, $color);
        $rule = $color->dim($finding->code);
        $message = $finding->message . $this->formatBreachSuffix($finding);
        $symbol = $this->formatSymbol($finding);

        // Format: file:line: severity[rule]: message (accepted at X, now Y) (symbol)
        $location = $line !== null && $finding->location->precise ? "{$file}:{$line}" : $file;

        return \sprintf('%s: %s[%s]: %s%s', $location, $severity, $rule, $message, $symbol);
    }

    /**
     * " (accepted at 25, now 31)" on a measured breach, '' otherwise (ADR 0017).
     */
    private function formatBreachSuffix(Finding $finding): string
    {
        $breach = AcceptedLevelNarrator::describe($finding);

        return $breach === null ? '' : \sprintf(' (%s)', $breach);
    }

    private function formatSeverity(Severity $severity, AnsiColor $color): string
    {
        return match ($severity) {
            Severity::Error => $color->red('error'),
            Severity::Warning => $color->yellow('warning'),
            Severity::Info => $color->cyan('info'),
        };
    }

    private function formatSymbol(Finding $finding): string
    {
        $symbol = $finding->symbolPath->getSymbolName();

        if ($symbol !== null && $symbol !== '') {
            return " ({$symbol})";
        }

        if ($finding->symbolPath->getType() === SymbolType::Namespace_) {
            $namespace = $finding->symbolPath->toString();

            return $namespace !== '' ? \sprintf(' (namespace: %s)', $namespace) : '';
        }

        return '';
    }

    private function formatSummary(Report $report, AnsiColor $color): string
    {
        $version = Version::get();
        $parts = [
            \sprintf('%d error(s)', $report->errorCount),
            \sprintf('%d warning(s)', $report->warningCount),
        ];
        if ($report->infoCount > 0) {
            $parts[] = \sprintf('%d info', $report->infoCount);
        }
        $summary = \sprintf(
            'Qualimetrix %s: %s in %d file(s)',
            $version,
            implode(', ', $parts),
            $report->filesAnalyzed,
        );

        if ($report->errorCount > 0) {
            return $color->boldRed($summary);
        }

        if ($report->warningCount > 0) {
            return $color->boldYellow($summary);
        }

        if ($report->infoCount > 0) {
            return $color->boldCyan($summary);
        }

        return $color->boldGreen($summary);
    }
}
