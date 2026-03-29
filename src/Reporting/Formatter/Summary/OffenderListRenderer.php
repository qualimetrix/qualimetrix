<?php

declare(strict_types=1);

namespace Qualimetrix\Reporting\Formatter\Summary;

use Qualimetrix\Reporting\Filter\ViolationFilter;
use Qualimetrix\Reporting\Formatter\Support\AnsiColor;
use Qualimetrix\Reporting\FormatterContext;
use Qualimetrix\Reporting\Health\NamespaceDrillDown;
use Qualimetrix\Reporting\Health\WorstOffender;
use Qualimetrix\Reporting\Report;

/**
 * Renders the worst namespaces and worst classes sections for the summary formatter.
 */
final class OffenderListRenderer
{
    private const int MAX_WORST_OFFENDERS = 3;

    /** Default thresholds for worst offender score colorization (from health.overall defaults) */
    private const float OFFENDER_WARN_THRESHOLD = 50.0;
    private const float OFFENDER_ERR_THRESHOLD = 30.0;

    public function __construct(
        private readonly ViolationFilter $filter,
        private readonly NamespaceDrillDown $namespaceDrillDown,
    ) {}

    /**
     * Renders the worst namespaces section.
     *
     * @param list<string> $lines
     */
    public function renderWorstNamespaces(
        Report $report,
        AnsiColor $color,
        FormatterContext $context,
        array &$lines,
    ): void {
        $offenders = $this->filter->filterWorstOffenders($report->worstNamespaces, $context);

        if ($offenders === []) {
            return;
        }

        // Skip namespace section for single-file analysis
        if ($report->filesAnalyzed <= 1) {
            return;
        }

        $lines[] = $color->bold('Worst namespaces');
        $this->renderOffenderList($offenders, $color, $lines, showClassCount: true, context: $context);
        $lines[] = '';
    }

    /**
     * Renders the worst classes section.
     *
     * @param list<string> $lines
     */
    public function renderWorstClasses(
        Report $report,
        AnsiColor $color,
        FormatterContext $context,
        array &$lines,
    ): void {
        $offenders = $this->resolveWorstClasses($report, $context);

        if ($offenders === []) {
            return;
        }

        // Skip class section for single-file analysis
        if ($report->filesAnalyzed <= 1) {
            return;
        }

        $lines[] = $color->bold('Worst classes');
        $this->renderOffenderList($offenders, $color, $lines, showClassCount: false, context: $context);
        $lines[] = '';
    }

    /**
     * Resolves worst classes: builds from namespace metrics when filtering, otherwise uses pre-built list.
     *
     * @return list<WorstOffender>
     */
    public function resolveWorstClasses(Report $report, FormatterContext $context): array
    {
        if ($context->namespace !== null && $report->metrics !== null) {
            return $this->namespaceDrillDown->buildWorstClasses($report->metrics, $context->namespace, $report->violations);
        }

        return $this->filter->filterWorstOffenders($report->worstClasses, $context);
    }

    /**
     * Renders truncated offender list with "+N more" indicator.
     *
     * @param list<WorstOffender> $offenders
     * @param list<string> $lines
     */
    private function renderOffenderList(array $offenders, AnsiColor $color, array &$lines, bool $showClassCount, FormatterContext $context): void
    {
        $topN = $this->getTopN($context);

        $offenders = $this->rankOffenders($offenders, $context);

        foreach (\array_slice($offenders, 0, $topN) as $offender) {
            $this->renderWorstOffender($offender, $color, $lines, $showClassCount);
        }

        $remaining = \count($offenders) - $topN;
        if ($remaining > 0) {
            $lines[] = $color->dim(\sprintf('  +%d more (use --format=html or --format-opt=top=%d)', $remaining, \count($offenders)));
        }
    }

    /**
     * Re-ranks offenders when rank-by=density is requested.
     *
     * @param list<WorstOffender> $offenders
     *
     * @return list<WorstOffender>
     */
    private function rankOffenders(array $offenders, FormatterContext $context): array
    {
        return WorstOffender::rankByDensity($offenders, $context->getOption('rank-by', 'count'));
    }

    private function getTopN(FormatterContext $context): int
    {
        $topOpt = $context->options['top'] ?? null;

        if ($topOpt !== null && is_numeric($topOpt) && (int) $topOpt > 0) {
            return (int) $topOpt;
        }

        return self::MAX_WORST_OFFENDERS;
    }

    /**
     * @param list<string> $lines
     */
    private function renderWorstOffender(
        WorstOffender $offender,
        AnsiColor $color,
        array &$lines,
        bool $showClassCount,
    ): void {
        $name = $offender->symbolPath->toString();
        $scoreText = $this->formatValue($offender->healthOverall);

        $meta = [];
        if ($showClassCount && $offender->classCount > 0) {
            $meta[] = \sprintf('%d classes', $offender->classCount);
        }
        if ($offender->violationCount > 0) {
            $meta[] = \sprintf('%d violations', $offender->violationCount);
        }
        if ($offender->violationDensity !== null && $offender->violationDensity > 0.0) {
            $meta[] = \sprintf('%.1f/100 LOC', $offender->violationDensity);
        }

        $metaStr = $meta !== [] ? $color->dim(' (' . implode(', ', $meta) . ')') : '';
        $reasonStr = $offender->reason !== '' ? $color->dim(" — {$offender->reason}") : '';

        $lines[] = \sprintf(
            '  %s %s%s%s',
            $this->colorizeScore($scoreText, $offender->healthOverall, self::OFFENDER_WARN_THRESHOLD, self::OFFENDER_ERR_THRESHOLD, $color),
            $color->bold($name),
            $metaStr,
            $reasonStr,
        );
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
