<?php

declare(strict_types=1);

namespace Qualimetrix\Reporting\Formatter\Summary;

use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Reporting\Formatter\Support\AnsiColor;
use Qualimetrix\Reporting\FormatterContext;
use Qualimetrix\Reporting\Health\DecompositionItem;
use Qualimetrix\Reporting\Health\HealthScore;
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

        $headerSuffix = '';
        if ($context->namespace !== null) {
            $headerSuffix = ' ' . $color->dim(\sprintf('[namespace: %s]', $context->namespace));
        } elseif ($context->class !== null) {
            $headerSuffix = ' ' . $color->dim(\sprintf('[class: %s]', $context->class));
        }

        if ($overall !== null && $overall->score !== null) {
            $healthLine = $color->bold('Health') . $headerSuffix . ' '
                . $this->renderHealthBar($overall->score, $overall->warningThreshold, $overall->errorThreshold, $terminalWidth, $ascii, $color)
                . ' ' . $this->formatScore($overall->score, $color, $overall->warningThreshold, $overall->errorThreshold)
                . ' ' . $color->dim($overall->label);

            // C2: Show flat (direct) score when namespace drill-down uses recursive aggregation
            if ($context->namespace !== null && $report->metrics !== null) {
                $nsPath = SymbolPath::forNamespace($context->namespace);
                $flatOverall = $report->metrics->get($nsPath)->get('health.overall');
                if ($flatOverall !== null) {
                    $flatScore = (float) $flatOverall;
                    $delta = abs($overall->score - $flatScore);
                    if ($delta > 10.0) {
                        // Large difference: explain why scores differ
                        $healthLine .= $color->dim(\sprintf(
                            ' (direct classes: %.1f%% — sub-namespaces raise the score)',
                            $flatScore,
                        ));
                    } elseif ($delta > 5.0) {
                        $healthLine .= $color->dim(\sprintf(' (direct: %.1f%%)', $flatScore));
                    }
                }
            }

            $lines[] = $healthLine;
            $lines[] = '';
        }

        // Dynamic padding based on longest dimension name
        $padWidth = 0;
        foreach ($dimensions as $hs) {
            $padWidth = max($padWidth, \strlen(ucfirst($hs->name)));
        }
        $padWidth = max($padWidth, 10); // minimum padding
        $decompositionIndent = str_repeat(' ', $padWidth + 4); // 2 indent + padWidth + 2 space

        foreach ($dimensions as $hs) {
            $label = str_pad(ucfirst($hs->name), $padWidth);

            if ($hs->score === null) {
                // N/A dimension (e.g., typing with no classes)
                $lines[] = \sprintf('  %s %s %s', $label, $color->dim('N/A'), $color->dim($hs->label));

                continue;
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

        // H8: Explain that dimensions have independent scales when labels might seem contradictory
        if (\count($dimensions) > 1) {
            $thresholds = array_unique(array_map(
                static fn(HealthScore $hs): float => $hs->warningThreshold,
                array_values($dimensions),
            ));

            if (\count($thresholds) > 1) {
                $lines[] = $color->dim('  * Labels reflect per-dimension scales (e.g., Typing requires >80% for Acceptable)');
            }
        }

        $lines[] = '';
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
