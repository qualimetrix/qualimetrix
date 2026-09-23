<?php

declare(strict_types=1);

namespace Qualimetrix\Reporting\Formatter\Detail;

use Qualimetrix\Analysis\Evidence\Prioritization\Debt\DebtCalculator;
use Qualimetrix\Analysis\Finding\Contract\Finding;
use Qualimetrix\Reporting\Formatter\Ansi\AnsiColor;
use Qualimetrix\Reporting\FormatterContext;

/** Composes detailed finding output and its technical-debt breakdown. */
final class DetailedFindingRenderer
{
    private readonly FindingDetailRenderer $findingDetailRenderer;
    private readonly DebtBreakdownRenderer $debtBreakdownRenderer;

    public function __construct(DebtCalculator $debtCalculator)
    {
        $this->findingDetailRenderer = new FindingDetailRenderer();
        $this->debtBreakdownRenderer = new DebtBreakdownRenderer($debtCalculator);
    }

    /**
     * The `--detail` listing under the context's cap: the whole list is put in
     * its printed order first and cut second, so the findings shown are the
     * first ones `--detail=all` would print, never the first ones the rules
     * happened to produce.
     *
     * @param list<Finding> $findings every finding the listing is about
     *
     * @return string Formatted detail block (without trailing newline)
     */
    public function renderCapped(array $findings, FormatterContext $context): string
    {
        $ordered = FindingDetailRenderer::order($findings, $context);
        $cap = $context->detailLimit;
        $shown = $cap === null || $cap === 0 ? $ordered : \array_slice($ordered, 0, $cap);
        $block = $this->render($shown, $context, $findings);

        $remaining = \count($ordered) - \count($shown);
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
    public function render(array $findings, FormatterContext $context, ?array $allFindings = null): string
    {
        if ($findings === []) {
            $label = $context->namespace !== null || $context->class !== null
                ? 'No violations in this scope.'
                : 'No violations found.';

            return (new AnsiColor($context->useColor))->boldGreen($label);
        }

        return implode("\n", [
            $this->findingDetailRenderer->render($findings, $context),
            $this->debtBreakdownRenderer->render($findings, $allFindings),
        ]);
    }
}
