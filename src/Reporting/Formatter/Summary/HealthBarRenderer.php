<?php

declare(strict_types=1);

namespace Qualimetrix\Reporting\Formatter\Summary;

use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Definition\HealthDimension;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Contract\Score\DecompositionItem;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Contract\Score\HealthScore;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Reporting\Formatter\Support\AnsiColor;
use Qualimetrix\Reporting\FormatterContext;
use Qualimetrix\Reporting\Health\HealthScoreResolver;
use Qualimetrix\Reporting\Report;

/**
 * Renders health bars and dimension scores for the summary formatter.
 */
final class HealthBarRenderer
{
    private const int MIN_BAR_WIDTH = 20;
    private const int DEFAULT_TERMINAL_WIDTH = 80;

    public function __construct(
        private readonly HealthScoreResolver $healthScoreResolver,
    ) {}

    /**
     * Renders the full health scores section into the lines array.
     *
     * @param list<string> $lines
     */
    public function render(
        Report $report,
        FormatterContext $context,
        AnsiColor $color,
        int $terminalWidth,
        bool $ascii,
        array &$lines,
    ): void {
        $healthScores = $this->healthScoreResolver->resolve($report, $context);

        if ($healthScores === []) {
            $lines[] = $color->dim('Health: insufficient data');
            $lines[] = '';

            return;
        }

        // Render overall first, then dimensions
        $overall = $healthScores['overall'] ?? null;
        $dimensions = array_filter(
            $healthScores,
            static fn(HealthScore $hs): bool => $hs->name !== 'overall',
        );

        $overallLine = $this->renderOverallLine($report, $context, $overall, $terminalWidth, $ascii, $color);
        if ($overallLine !== null) {
            $lines[] = $overallLine;
            $lines[] = '';
        }

        $this->renderDimensionLines($dimensions, $terminalWidth, $ascii, $color, $lines);

        // H8: Explain that dimensions have independent scales when labels might seem contradictory
        $scaleNote = $this->buildScaleNote($dimensions, $color);
        if ($scaleNote !== null) {
            $lines[] = $scaleNote;
        }

        $lines[] = '';
    }

    /**
     * Renders the overall "Health" line (bar, score, label, flat-score note), or null when
     * there is no overall score to show.
     */
    private function renderOverallLine(
        Report $report,
        FormatterContext $context,
        ?HealthScore $overall,
        int $terminalWidth,
        bool $ascii,
        AnsiColor $color,
    ): ?string {
        if ($overall === null || $overall->score === null) {
            return null;
        }

        $headerSuffix = $this->buildHeaderSuffix($context, $color);

        $healthLine = $color->bold('Health') . $headerSuffix . ' '
            . $this->renderHealthBar($overall->score, $overall->warningThreshold, $overall->errorThreshold, $terminalWidth, $ascii, $color)
            . ' ' . $this->formatScore($overall->score, $color, $overall->warningThreshold, $overall->errorThreshold)
            . ' ' . $color->dim($overall->label);

        // C2: Show flat (direct) score when namespace drill-down uses recursive aggregation
        $flatScoreNote = $this->buildFlatScoreNote($report, $context, $overall->score, $color);
        if ($flatScoreNote !== null) {
            $healthLine .= $flatScoreNote;
        }

        return $healthLine;
    }

    private function buildHeaderSuffix(FormatterContext $context, AnsiColor $color): string
    {
        if ($context->namespace !== null) {
            return ' ' . $color->dim(\sprintf('[namespace: %s]', $context->namespace));
        }

        if ($context->class !== null) {
            return ' ' . $color->dim(\sprintf('[class: %s]', $context->class));
        }

        return '';
    }

    private function buildFlatScoreNote(Report $report, FormatterContext $context, float $overallScore, AnsiColor $color): ?string
    {
        if ($context->namespace === null || $report->metrics === null) {
            return null;
        }

        $nsPath = SymbolPath::forNamespace($context->namespace);
        $flatOverall = $report->metrics->get($nsPath)->get(HealthDimension::Overall->value);
        if ($flatOverall === null) {
            return null;
        }

        $flatScore = (float) $flatOverall;
        $delta = abs($overallScore - $flatScore);

        if ($delta > 10.0) {
            // Large difference: explain why scores differ
            return $color->dim(\sprintf(
                ' (direct classes: %.1f%% — sub-namespaces raise the score)',
                $flatScore,
            ));
        }

        if ($delta > 5.0) {
            return $color->dim(\sprintf(' (direct: %.1f%%)', $flatScore));
        }

        return null;
    }

    /**
     * @param array<string, HealthScore> $dimensions
     * @param list<string> $lines
     */
    private function renderDimensionLines(array $dimensions, int $terminalWidth, bool $ascii, AnsiColor $color, array &$lines): void
    {
        // Dynamic padding based on longest dimension name
        $padWidth = $this->calculatePadWidth($dimensions);
        $decompositionIndent = str_repeat(' ', $padWidth + 4); // 2 indent + padWidth + 2 space

        foreach ($dimensions as $hs) {
            $this->renderDimensionLine($hs, $padWidth, $terminalWidth, $ascii, $color, $decompositionIndent, $lines);
        }
    }

    /**
     * @param array<string, HealthScore> $dimensions
     */
    private function calculatePadWidth(array $dimensions): int
    {
        $padWidth = 0;
        foreach ($dimensions as $hs) {
            $padWidth = max($padWidth, \strlen(ucfirst($hs->name)));
        }

        return max($padWidth, 10); // minimum padding
    }

    /**
     * @param list<string> $lines
     */
    private function renderDimensionLine(
        HealthScore $hs,
        int $padWidth,
        int $terminalWidth,
        bool $ascii,
        AnsiColor $color,
        string $decompositionIndent,
        array &$lines,
    ): void {
        $label = str_pad(ucfirst($hs->name), $padWidth);

        if ($hs->score === null) {
            // N/A dimension (e.g., typing with no classes)
            $lines[] = \sprintf('  %s %s %s', $label, $color->dim('N/A'), $color->dim($hs->label));

            return;
        }

        $scoreStr = $this->formatScore($hs->score, $color, $hs->warningThreshold, $hs->errorThreshold);

        if ($terminalWidth < self::DEFAULT_TERMINAL_WIDTH) {
            // Narrow terminal: no bars
            $lines[] = \sprintf('  %s %s %s', $label, $scoreStr, $color->dim($hs->label));
        } else {
            $bar = $this->renderHealthBar($hs->score, $hs->warningThreshold, $hs->errorThreshold, $terminalWidth, $ascii, $color);
            $lines[] = \sprintf('  %s %s %s %s', $label, $bar, $scoreStr, $color->dim($hs->label));
        }

        // Decomposition for dimensions needing attention
        foreach ($hs->decomposition as $item) {
            $lines[] = $this->renderDecompositionItem($item, $color, $decompositionIndent);
        }
    }

    /**
     * @param array<string, HealthScore> $dimensions
     */
    private function buildScaleNote(array $dimensions, AnsiColor $color): ?string
    {
        if (\count($dimensions) <= 1) {
            return null;
        }

        $thresholds = array_unique(array_map(
            static fn(HealthScore $hs): float => $hs->warningThreshold,
            array_values($dimensions),
        ));

        if (\count($thresholds) <= 1) {
            return null;
        }

        return $color->dim('  * Labels reflect per-dimension scales (e.g., Typing requires >80% for Acceptable)');
    }

    private function renderHealthBar(
        float $score,
        float $warnThreshold,
        float $errThreshold,
        int $terminalWidth,
        bool $ascii,
        AnsiColor $color,
    ): string {
        $barWidth = max(self::MIN_BAR_WIDTH, min(30, $terminalWidth - 50));
        $normalizedScore = (is_nan($score) || is_infinite($score)) ? 0.0 : $score;
        $filled = (int) round($normalizedScore / 100 * $barWidth);
        $filled = max(0, min($barWidth, $filled));
        $empty = $barWidth - $filled;

        if ($ascii) {
            $bar = str_repeat('#', $filled) . str_repeat('.', $empty);

            return $this->colorizeScore('[' . $bar . ']', $score, $warnThreshold, $errThreshold, $color);
        }

        $bar = str_repeat('█', $filled) . str_repeat('░', $empty);

        return $this->colorizeScore($bar, $score, $warnThreshold, $errThreshold, $color);
    }

    private function formatScore(float $score, AnsiColor $color, float $warnThreshold, float $errThreshold): string
    {
        $formatted = $this->formatValue($score) . '%';

        return $this->colorizeScore($formatted, $score, $warnThreshold, $errThreshold, $color);
    }

    private function colorizeScore(string $text, float $score, float $warnThreshold, float $errThreshold, AnsiColor $color): string
    {
        if ($score > $warnThreshold) {
            return $color->green($text);
        }

        if ($score > $errThreshold) {
            return $color->yellow($text);
        }

        return $color->red($text);
    }

    private function renderDecompositionItem(DecompositionItem $item, AnsiColor $color, string $indent): string
    {
        $value = $this->formatValue($item->value);
        $explanation = $item->explanation !== '' ? " — {$item->explanation}" : '';

        return \sprintf(
            '%s%s %s: %s (target: %s)%s',
            $indent,
            $color->dim('↳'),
            $item->humanName,
            $color->bold($value),
            $color->dim($item->goodValue),
            $color->dim($explanation),
        );
    }

    private function formatValue(float $value): string
    {
        if (is_nan($value) || is_infinite($value)) {
            return '—';
        }

        if ($value === floor($value) && abs($value) < 1e12) {
            return (string) (int) $value;
        }

        return \sprintf('%.1f', $value);
    }
}
