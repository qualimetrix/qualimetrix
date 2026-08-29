<?php

declare(strict_types=1);

namespace Qualimetrix\Reporting\Formatter\Support;

use Qualimetrix\Analysis\Evidence\Prioritization\Debt\DebtCalculator;
use Qualimetrix\Analysis\Finding\Contract\Finding;
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
