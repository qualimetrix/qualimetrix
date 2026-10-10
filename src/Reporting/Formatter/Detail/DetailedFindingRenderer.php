<?php

declare(strict_types=1);

namespace Qualimetrix\Reporting\Formatter\Detail;

use Qualimetrix\Analysis\Evidence\Prioritization\Debt\DebtCalculator;
use Qualimetrix\Analysis\Finding\Contract\Finding;
use Qualimetrix\Reporting\Formatter\Ansi\AnsiColor;
use Qualimetrix\Reporting\FormatterContext;

/** Composes detailed finding output and its technical-debt breakdown. *
 * @qmx-threshold coupling.instability warning=0.800001 -- Detail publication composes ordering, namespace attribution, finding and debt rendering through few consumers; extracting the composition transfers its outward dependencies.
 */
final class DetailedFindingRenderer
{
    private readonly FindingDetailRenderer $findingDetailRenderer;
    private readonly DebtBreakdownRenderer $debtBreakdownRenderer;

    public function __construct(DebtCalculator $debtCalculator)
    {
        $this->findingDetailRenderer = new FindingDetailRenderer();
        $this->debtBreakdownRenderer = new DebtBreakdownRenderer($debtCalculator);
    }

    /** Selects the worst findings before applying the requested print grouping. */
    public function renderCapped(\Qualimetrix\Reporting\Report $report, FormatterContext $context): string
    {
        $findings = $report->findings;
        $cap = $context->detailLimit;
        $shown = $cap === null || $cap === 0 ? $findings
            : \Qualimetrix\Reporting\Formatter\Ordering\FindingSorter::worstFirst($findings, $report->topIssues, $cap);
        $block = $this->render($shown, $context, $report->fileNamespaces, $findings);

        $remaining = \count($findings) - \count($shown);
        if ($remaining === 0) {
            return $block;
        }

        return $block . "\n\n" . (new AnsiColor($context->useColor))->dim(\sprintf(
            '... and %d more. Use --detail=all to see all violations',
            $remaining,
        ));
    }

    /**
     * @param list<Finding> $findings Findings to display (may be truncated by --detail limit)
     * @param list<Finding>|null $allFindings Full finding list for debt calculation (defaults to $findings)
     *
     * @return string Formatted detail block (without trailing newline)
     */
    public function render(array $findings, FormatterContext $context, \Qualimetrix\Analysis\Evidence\Measurement\Contract\FileNamespaceIndex $fileNamespaces, ?array $allFindings = null): string
    {
        if ($findings === []) {
            $label = $context->namespace !== null || $context->class !== null
                ? 'No violations in this scope.'
                : 'No violations found.';

            return (new AnsiColor($context->useColor))->boldGreen($label);
        }

        return implode("\n", [
            $this->findingDetailRenderer->render($findings, $context, $fileNamespaces),
            $this->debtBreakdownRenderer->render($findings, $allFindings),
        ]);
    }
}
