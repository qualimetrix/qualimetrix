<?php

declare(strict_types=1);

namespace Qualimetrix\Reporting\Formatter\Support;

use Qualimetrix\Analysis\Evidence\Prioritization\Debt\DebtCalculator;
use Qualimetrix\Analysis\Finding\Contract\Violation;
use Qualimetrix\Reporting\FormatterContext;

/** Composes detailed violation output and its technical-debt breakdown. */
final class DetailedViolationRenderer
{
    private readonly ViolationDetailRenderer $violationDetailRenderer;
    private readonly DebtBreakdownRenderer $debtBreakdownRenderer;

    public function __construct(DebtCalculator $debtCalculator)
    {
        $this->violationDetailRenderer = new ViolationDetailRenderer();
        $this->debtBreakdownRenderer = new DebtBreakdownRenderer($debtCalculator);
    }

    /**
     * @param list<Violation> $violations Violations to display (may be truncated by --detail limit)
     * @param list<Violation>|null $allViolations Full violation list for debt calculation (defaults to $violations)
     *
     * @return string Formatted detail block (without trailing newline)
     */
    public function render(array $violations, FormatterContext $context, ?array $allViolations = null): string
    {
        if ($violations === []) {
            $label = $context->namespace !== null || $context->class !== null
                ? 'No violations in this scope.'
                : 'No violations found.';

            return (new AnsiColor($context->useColor))->boldGreen($label);
        }

        return implode("\n", [
            $this->violationDetailRenderer->render($violations, $context),
            $this->debtBreakdownRenderer->render($violations, $allViolations),
        ]);
    }
}
