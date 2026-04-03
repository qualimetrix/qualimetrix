<?php

declare(strict_types=1);

namespace Qualimetrix\Reporting\Formatter\Summary;

use Qualimetrix\Reporting\Formatter\FormatterInterface;
use Qualimetrix\Reporting\Formatter\Support\AnsiColor;
use Qualimetrix\Reporting\Formatter\Support\DetailedViolationRenderer;
use Qualimetrix\Reporting\FormatterContext;
use Qualimetrix\Reporting\GroupBy;
use Qualimetrix\Reporting\Report;

/**
 * Summary formatter -- default CLI output.
 *
 * Shows health overview, worst offenders, and contextual hints in one screen.
 * For detailed violation listing, use --format=text.
 */
final class SummaryFormatter implements FormatterInterface
{
    private const int DEFAULT_TERMINAL_WIDTH = 80;

    public function __construct(
        private readonly DetailedViolationRenderer $detailedRenderer,
        private readonly HealthBarRenderer $healthBarRenderer,
        private readonly OffenderListRenderer $offenderListRenderer,
        private readonly TopIssuesRenderer $topIssuesRenderer,
        private readonly ViolationSummaryRenderer $violationSummaryRenderer,
        private readonly HintRenderer $hintRenderer,
    ) {}

    public function format(Report $report, FormatterContext $context): string
    {
        $color = new AnsiColor($context->useColor);
        $terminalWidth = $context->terminalWidth > 0 ? $context->terminalWidth : self::DEFAULT_TERMINAL_WIDTH;
        $ascii = (bool) getenv('QMX_ASCII');
        $lines = [];

        $this->renderHeader($report, $context, $color, $lines);

        $this->healthBarRenderer->render($report, $context, $color, $terminalWidth, $ascii, $lines);
        $this->offenderListRenderer->renderWorstNamespaces($report, $color, $context, $lines);
        $this->offenderListRenderer->renderWorstClasses($report, $color, $context, $lines);
        $this->topIssuesRenderer->render($report, $context, $color, $lines);
        $this->violationSummaryRenderer->render($report, $context, $color, $lines);

        $this->hintRenderer->render($report, $context, $color, $lines);

        // Append detailed violation list when --detail is used
        if ($context->isDetailEnabled() && !$report->isEmpty()) {
            $detailViolations = $report->violations;
            if ($detailViolations !== []) {
                $limit = $context->detailLimit;
                $totalCount = \count($detailViolations);
                $showAll = $limit === null || $limit === 0 || $totalCount <= $limit;
                $displayViolations = $showAll ? $detailViolations : \array_slice($detailViolations, 0, $limit);

                $lines[] = '';
                $lines[] = $color->bold('Violations');
                $lines[] = $this->detailedRenderer->render($displayViolations, $context, $detailViolations);

                if (!$showAll) {
                    $remaining = $totalCount - $limit;
                    $lines[] = '';
                    $lines[] = $color->dim(\sprintf(
                        '... and %d more. Use --detail=all to see all violations',
                        $remaining,
                    ));
                }
            }
        }

        return implode("\n", $lines) . "\n";
    }

    public function getName(): string
    {
        return 'summary';
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
            'Qualimetrix — %d file%s analyzed',
            $report->filesAnalyzed,
            $report->filesAnalyzed === 1 ? '' : 's',
        );

        if ($context->scopedReporting) {
            $header .= ' (scoped)';
        }

        if ($context->namespace !== null) {
            $header .= \sprintf(' [namespace: %s]', $context->namespace);
        } elseif ($context->class !== null) {
            $header .= \sprintf(' [class: %s]', $context->class);
        }

        $header .= \sprintf(', %.1fs', $report->duration);

        $lines[] = $color->bold($header);
        $lines[] = '';
    }

}
