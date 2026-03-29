<?php

declare(strict_types=1);

namespace Qualimetrix\Reporting\Formatter\Health;

use Qualimetrix\Reporting\Formatter\FormatterInterface;
use Qualimetrix\Reporting\Formatter\Support\AnsiColor;
use Qualimetrix\Reporting\FormatterContext;
use Qualimetrix\Reporting\GroupBy;
use Qualimetrix\Reporting\Health\DecompositionItem;
use Qualimetrix\Reporting\Health\HealthContributor;
use Qualimetrix\Reporting\Health\HealthScore;
use Qualimetrix\Reporting\Health\HealthScoreResolver;
use Qualimetrix\Reporting\Report;

/**
 * Text-based health report formatter for terminal output.
 *
 * Renders a table of health dimensions with scores, status labels,
 * and threshold info, followed by decomposition details for each dimension.
 */
final class HealthTextFormatter implements FormatterInterface
{
    private const int DEFAULT_TERMINAL_WIDTH = 80;
    private const int NARROW_TERMINAL_THRESHOLD = 60;
    private const int DEFAULT_CONTRIBUTORS = 3;

    public function __construct(
        private readonly HealthScoreResolver $healthScoreResolver,
    ) {}

    public function format(Report $report, FormatterContext $context): string
    {
        $color = new AnsiColor($context->useColor);
        $terminalWidth = $context->terminalWidth > 0 ? $context->terminalWidth : self::DEFAULT_TERMINAL_WIDTH;
        $lines = [];

        $this->renderHeader($report, $context, $color, $lines);

        $healthScores = $this->healthScoreResolver->resolve($report, $context);

        if ($healthScores === []) {
            $lines[] = $color->dim('No health data available.');
            $lines[] = $color->dim('Run a full analysis with computed metrics enabled to see health scores.');
            $lines[] = '';

            return implode("\n", $lines) . "\n";
        }

        // Separate overall from dimension scores
        $overall = $healthScores['overall'] ?? null;
        $dimensions = array_filter(
            $healthScores,
            static fn(HealthScore $hs): bool => $hs->name !== 'overall',
        );

        $narrow = $terminalWidth < self::NARROW_TERMINAL_THRESHOLD;
        $contributorsLimit = (int) $context->getOption('contributors', (string) self::DEFAULT_CONTRIBUTORS);

        $this->renderTable($dimensions, $overall, $color, $narrow, $lines);

        if (!$narrow) {
            $this->renderDecompositions($dimensions, $color, $contributorsLimit, $lines);
        }

        $lines[] = '';

        return implode("\n", $lines) . "\n";
    }

    public function getName(): string
    {
        return 'health';
    }

    public function getDefaultGroupBy(): GroupBy
    {
        return GroupBy::None;
    }

    /**
     * @param list<string> $lines
     */
    private function renderHeader(Report $report, FormatterContext $context, AnsiColor $color, array &$lines): void
    {
        $header = \sprintf(
            'Health Report — %d file%s analyzed',
            $report->filesAnalyzed,
            $report->filesAnalyzed === 1 ? '' : 's',
        );

        if ($context->namespace !== null) {
            $header .= \sprintf(' [namespace: %s]', $context->namespace);
        } elseif ($context->class !== null) {
            $header .= \sprintf(' [class: %s]', $context->class);
        }

        $lines[] = $color->bold($header);
        $lines[] = '';
    }

    /**
     * @param array<string, HealthScore> $dimensions
     * @param list<string> $lines
     */
    private function renderTable(
        array $dimensions,
        ?HealthScore $overall,
        AnsiColor $color,
        bool $narrow,
        array &$lines,
    ): void {
        // Calculate column widths
        $allScores = $dimensions;
        if ($overall !== null) {
            $allScores['overall'] = $overall;
        }

        $nameWidth = 0;
        foreach ($allScores as $hs) {
            $nameWidth = max($nameWidth, \strlen(ucfirst($hs->name)));
        }
        $nameWidth = max($nameWidth, 9); // min "Dimension" header

        if ($narrow) {
            $this->renderNarrowTable($allScores, $dimensions, $overall, $color, $nameWidth, $lines);

            return;
        }

        // Header
        $header = \sprintf(
            '  %-' . $nameWidth . 's  %7s   %-12s  %s',
            'Dimension',
            'Score',
            'Status',
            'Thresholds',
        );
        $lines[] = $color->bold($header);
        $lines[] = '  ' . str_repeat("\u{2500}", $nameWidth + 40);

        // Dimension rows
        foreach ($dimensions as $hs) {
            $lines[] = $this->renderRow($hs, $color, $nameWidth);
        }

        // Overall row (separated)
        if ($overall !== null) {
            $lines[] = '  ' . str_repeat("\u{2500}", $nameWidth + 40);
            $lines[] = $this->renderRow($overall, $color, $nameWidth);
        }
    }

    /**
     * @param array<string, HealthScore> $allScores
     * @param array<string, HealthScore> $dimensions
     * @param list<string> $lines
     */
    private function renderNarrowTable(
        array $allScores,
        array $dimensions,
        ?HealthScore $overall,
        AnsiColor $color,
        int $nameWidth,
        array &$lines,
    ): void {
        foreach ($dimensions as $hs) {
            $name = str_pad(ucfirst($hs->name), $nameWidth);

            if ($hs->score === null) {
                $lines[] = \sprintf('  %s  %s', $name, $color->dim($hs->label));
            } else {
                $scoreStr = $this->colorizeScore(\sprintf('%5.1f%%', $hs->score), $hs, $color);
                $lines[] = \sprintf('  %s  %s  %s', $name, $scoreStr, $color->dim($hs->label));
            }
        }

        if ($overall !== null) {
            $name = str_pad(ucfirst($overall->name), $nameWidth);

            if ($overall->score === null) {
                $lines[] = \sprintf('  %s  %s', $name, $color->dim($overall->label));
            } else {
                $scoreStr = $this->colorizeScore(\sprintf('%5.1f%%', $overall->score), $overall, $color);
                $lines[] = \sprintf('  %s  %s  %s', $name, $scoreStr, $color->bold($overall->label));
            }
        }
    }

    private function renderRow(HealthScore $hs, AnsiColor $color, int $nameWidth): string
    {
        $name = str_pad(ucfirst($hs->name), $nameWidth);

        if ($hs->score === null) {
            return \sprintf(
                '  %s  %s   %s',
                $name,
                $this->ansiRightPad($color->dim('N/A'), 7),
                $this->ansiPad($color->dim($hs->label), 12),
            );
        }

        $scoreStr = $this->ansiRightPad(
            $this->colorizeScore(\sprintf('%5.1f%%', $hs->score), $hs, $color),
            7,
        );
        $statusIcon = $hs->score > $hs->warningThreshold ? "\u{25B2}" : "\u{25BC}";
        $statusStr = $this->colorizeScore($statusIcon . ' ' . $hs->label, $hs, $color);
        $paddedStatus = $this->ansiPad($statusStr, 12);
        $thresholds = $color->dim(\sprintf(
            'warn < %.0f  err < %.0f',
            $hs->warningThreshold,
            $hs->errorThreshold,
        ));

        return \sprintf(
            '  %s  %s   %s  %s',
            $name,
            $scoreStr,
            $paddedStatus,
            $thresholds,
        );
    }

    /**
     * @param array<string, HealthScore> $dimensions
     * @param list<string> $lines
     */
    private function renderDecompositions(array $dimensions, AnsiColor $color, int $contributorsLimit, array &$lines): void
    {
        $hasDecomposition = false;

        foreach ($dimensions as $hs) {
            if ($hs->decomposition === [] && $hs->worstContributors === []) {
                continue;
            }

            if (!$hasDecomposition) {
                $lines[] = '';
                $hasDecomposition = true;
            }

            $lines[] = $color->bold(\sprintf('  %s decomposition:', ucfirst($hs->name)));

            foreach ($hs->decomposition as $item) {
                $lines[] = $this->renderDecompositionItem($item, $color);
            }

            $this->renderContributors($hs->worstContributors, $color, $contributorsLimit, $lines);
        }
    }

    private function renderDecompositionItem(DecompositionItem $item, AnsiColor $color): string
    {
        $value = $this->formatValue($item->value);
        $boldValue = $color->bold($value);
        $paddedValue = $this->ansiRightPad($boldValue, 8);
        $explanation = $item->explanation !== '' ? ' — ' . $item->explanation : '';

        return \sprintf(
            '    %-24s %s   (target: %s)%s',
            $item->humanName,
            $paddedValue,
            $color->dim($item->goodValue),
            $color->dim($explanation),
        );
    }

    /**
     * @param list<HealthContributor> $contributors
     * @param list<string> $lines
     */
    private function renderContributors(array $contributors, AnsiColor $color, int $limit, array &$lines): void
    {
        if ($limit <= 0 || $contributors === []) {
            return;
        }

        $visible = \array_slice($contributors, 0, $limit);

        $lines[] = $color->dim('    Worst contributors:');

        foreach ($visible as $contributor) {
            $metricParts = [];

            foreach ($contributor->metricValues as $key => $value) {
                $formattedValue = \is_float($value) ? \sprintf('%.1f', $value) : (string) $value;
                $metricParts[] = \sprintf('%s=%s', $key, $formattedValue);
            }

            $metricsStr = implode('  ', $metricParts);
            $lines[] = \sprintf('      %-30s %s', $contributor->className, $color->dim($metricsStr));
        }
    }

    private function colorizeScore(string $text, HealthScore $hs, AnsiColor $color): string
    {
        if ($hs->score === null) {
            return $color->dim($text);
        }

        if ($hs->score > $hs->warningThreshold) {
            return $color->green($text);
        }

        if ($hs->score > $hs->errorThreshold) {
            return $color->yellow($text);
        }

        return $color->red($text);
    }

    /**
     * Left-pads a string to the given width, accounting for invisible ANSI escape sequences.
     */
    private function ansiPad(string $text, int $width): string
    {
        $visibleLength = mb_strlen((string) preg_replace('/\e\[[0-9;]*m/', '', $text));
        $padding = max(0, $width - $visibleLength);

        return $text . str_repeat(' ', $padding);
    }

    /**
     * Right-aligns a string to the given width, accounting for invisible ANSI escape sequences.
     */
    private function ansiRightPad(string $text, int $width): string
    {
        $visibleLength = mb_strlen((string) preg_replace('/\e\[[0-9;]*m/', '', $text));
        $padding = max(0, $width - $visibleLength);

        return str_repeat(' ', $padding) . $text;
    }

    private function formatValue(float $value): string
    {
        if (is_nan($value) || is_infinite($value)) {
            return "\u{2014}";
        }

        if ($value === floor($value) && abs($value) < 1e12) {
            return (string) (int) $value;
        }

        return \sprintf('%.1f', $value);
    }
}
