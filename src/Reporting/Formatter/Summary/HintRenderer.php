<?php

declare(strict_types=1);

namespace Qualimetrix\Reporting\Formatter\Summary;

use Qualimetrix\Reporting\Formatter\Support\AnsiColor;
use Qualimetrix\Reporting\FormatterContext;
use Qualimetrix\Reporting\Report;

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

        $detailHint = $this->buildDetailHint($report, $context);
        if ($detailHint !== null) {
            $hints[] = $detailHint;
        }

        $scopeHint = $this->buildScopeHint($context);
        if ($scopeHint !== null) {
            $hints[] = $scopeHint;
        }

        $drillDownHint = $this->buildDrillDownHint($report, $context);
        if ($drillDownHint !== null) {
            $hints[] = $drillDownHint;
        }

        $hints[] = '--format=html -o report.html for full report';

        $lines[] = $color->dim('Hints: ' . implode(' | ', $hints));
    }

    private function buildDetailHint(Report $report, FormatterContext $context): ?string
    {
        if ($report->isEmpty() || $context->isDetailEnabled()) {
            return null;
        }

        return '--detail to see violations (top 200)';
    }

    private function buildScopeHint(FormatterContext $context): ?string
    {
        if (!$context->scopedReporting) {
            return null;
        }

        return 'scoped analysis — violations filtered to changed files only';
    }

    private function buildDrillDownHint(Report $report, FormatterContext $context): ?string
    {
        if ($report->healthScores === [] || $context->class !== null) {
            return null;
        }

        if ($context->namespace !== null) {
            return $this->buildClassDrillDownHint($report, $context);
        }

        return $this->buildNamespaceDrillDownHint($report);
    }

    private function buildClassDrillDownHint(Report $report, FormatterContext $context): ?string
    {
        $worstClasses = $this->offenderListRenderer->resolveWorstClasses($report, $context);
        $worstCls = $worstClasses[0] ?? null;
        if ($worstCls === null) {
            return null;
        }

        $clsName = $this->escapeForShell($worstCls->symbolPath->toString());

        return \sprintf('--class=%s to drill deeper', $clsName);
    }

    private function buildNamespaceDrillDownHint(Report $report): ?string
    {
        $worstNs = $report->worstNamespaces[0] ?? null;
        if ($worstNs === null) {
            return null;
        }

        $nsName = $this->escapeForShell($worstNs->symbolPath->toString());

        return \sprintf('--namespace=%s to drill down', $nsName);
    }

    private function escapeForShell(string $value): string
    {
        if (str_contains($value, '\\')) {
            return "'" . $value . "'";
        }

        return $value;
    }
}
