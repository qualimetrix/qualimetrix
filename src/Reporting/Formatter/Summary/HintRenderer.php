<?php

declare(strict_types=1);

namespace AiMessDetector\Reporting\Formatter\Summary;

use AiMessDetector\Reporting\Formatter\Support\AnsiColor;
use AiMessDetector\Reporting\FormatterContext;
use AiMessDetector\Reporting\Report;

/**
 * Renders contextual hints at the bottom of the summary output.
 */
final class HintRenderer
{
    public function __construct(
        private readonly OffenderListRenderer $offenderListRenderer,
    ) {}

    /**
     * @param list<string> $lines
     */
    public function render(Report $report, FormatterContext $context, AnsiColor $color, array &$lines): void
    {
        $hints = [];

        if (!$report->isEmpty() && !$context->isDetailEnabled()) {
            $hints[] = '--detail to see violations (top 200)';
        }

        if ($context->partialAnalysis) {
            $hints[] = 'run full analysis for project health overview';
        }

        if ($report->healthScores !== [] && $context->class === null) {
            if ($context->namespace !== null) {
                $worstClasses = $this->offenderListRenderer->resolveWorstClasses($report, $context);
                $worstCls = $worstClasses[0] ?? null;
                if ($worstCls !== null) {
                    $clsName = $this->escapeForShell($worstCls->symbolPath->toString());
                    $hints[] = \sprintf('--class=%s to drill deeper', $clsName);
                }
            } else {
                $worstNs = $report->worstNamespaces[0] ?? null;
                if ($worstNs !== null) {
                    $nsName = $this->escapeForShell($worstNs->symbolPath->toString());
                    $hints[] = \sprintf('--namespace=%s to drill down', $nsName);
                }
            }
        }

        $hints[] = '--format=health -o report.html for full report';

        $lines[] = $color->dim('Hints: ' . implode(' | ', $hints));
    }

    private function escapeForShell(string $value): string
    {
        if (str_contains($value, '\\')) {
            return "'" . $value . "'";
        }

        return $value;
    }
}
