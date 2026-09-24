<?php

declare(strict_types=1);

namespace Qualimetrix\Reporting\Formatter;

use Qualimetrix\Analysis\Evidence\Prioritization\Debt\DebtCalculator;
use Qualimetrix\Analysis\Finding\Contract\Finding;
use Qualimetrix\Analysis\Finding\Contract\Severity;
use Qualimetrix\Core\ProductIdentity;
use Qualimetrix\Core\Symbol\SymbolType;
use Qualimetrix\Core\Version;
use Qualimetrix\Reporting\Formatter\Ansi\AnsiColor;
use Qualimetrix\Reporting\Formatter\Detail\DetailedFindingRenderer;
use Qualimetrix\Reporting\Formatter\Ordering\FindingSorter;
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
        $formatted = $context->isDetailEnabled()
            ? $this->formatDetailed($report, $context)
            : $this->formatFlat($report, $context);

        // The single point every `text` path reaches, flat or --detail alike —
        // `text-verbose` delegates here too, so it inherits the pointer rather
        // than needing its own.
        $color = new AnsiColor($context->useColor);

        return $formatted . $color->dim(ProductIdentity::pointerText()) . "\n";
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
        array_push($lines, ...CoverageNarrator::lines($report));

        // Technical debt line (dimmed to visually distinguish from summary)
        $debt = $this->debtCalculator->calculate($report->findings);
        $lines[] = $color->dim(\sprintf('Technical debt: %s', $debt->formatTotal()));

        return implode("\n", $lines) . "\n";
    }

    private function formatDetailed(Report $report, FormatterContext $context): string
    {
        $color = new AnsiColor($context->useColor);
        $lines = [];

        $lines[] = $this->detailedRenderer->renderCapped($report->findings, $context);
        $lines[] = '';

        // Summary line
        $lines[] = $this->formatSummary($report, $color);
        array_push($lines, ...CoverageNarrator::lines($report));

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
        $message = PublishedFinding::annotatedMessage($finding);
        $symbol = $this->formatSymbol($finding);

        // Format: file:line: severity[rule]: message (accepted at X, now Y) (symbol)
        $location = $line !== null && $finding->location->precise ? "{$file}:{$line}" : $file;

        return \sprintf('%s: %s[%s]: %s%s', $location, $severity, $rule, $message, $symbol);
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

    /**
     * Under a `--namespace`/`--class` selection the counts are the selection's,
     * so the line says so, names what lies outside it, and takes its colour
     * from the whole run — those findings decide the exit code.
     */
    private function formatSummary(Report $report, AnsiColor $color): string
    {
        $outOfScope = $report->outOfScope;
        $summary = \sprintf(
            $outOfScope === null ? 'Qualimetrix %s: %s in %d file(s)' : 'Qualimetrix %s: %s in this scope (%d file(s) analyzed)',
            Version::get(),
            $this->severityCounts($report->errorCount, $report->warningCount, $report->infoCount),
            $report->filesAnalyzed,
        );

        $errors = $report->errorCount;
        $warnings = $report->warningCount;
        $info = $report->infoCount;
        if ($outOfScope !== null && $outOfScope->total() > 0) {
            $summary .= \sprintf(
                '; outside it: %s, which decide the exit code',
                $this->severityCounts($outOfScope->errorCount, $outOfScope->warningCount, $outOfScope->infoCount),
            );
            $errors += $outOfScope->errorCount;
            $warnings += $outOfScope->warningCount;
            $info += $outOfScope->infoCount;
        }

        return match (true) {
            $errors > 0 => $color->boldRed($summary),
            $warnings > 0 => $color->boldYellow($summary),
            $info > 0 => $color->boldCyan($summary),
            default => $color->boldGreen($summary),
        };
    }

    private function severityCounts(int $errors, int $warnings, int $info): string
    {
        $parts = [
            \sprintf('%d error(s)', $errors),
            \sprintf('%d warning(s)', $warnings),
        ];
        if ($info > 0) {
            $parts[] = \sprintf('%d info', $info);
        }

        return implode(', ', $parts);
    }
}
