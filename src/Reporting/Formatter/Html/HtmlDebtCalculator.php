<?php

declare(strict_types=1);

namespace Qualimetrix\Reporting\Formatter\Html;

use Qualimetrix\Core\Violation\Violation;
use Qualimetrix\Reporting\Debt\DebtCalculator;

/**
 * Computes and aggregates technical debt for HTML tree nodes.
 *
 * Assigns debt from partitioned violations to leaf nodes, then aggregates
 * debt and violation counts bottom-up through the tree hierarchy.
 *
 * @internal
 */
final readonly class HtmlDebtCalculator
{
    public function __construct(
        private DebtCalculator $debtCalculator,
    ) {}

    /**
     * Computes debt per node from partitioned violations.
     *
     * @param array<string, list<Violation>> $violationsByNode
     * @param array<string, HtmlTreeNode> $nodesByPath
     */
    public function computeDebt(
        array $violationsByNode,
        array $nodesByPath,
    ): void {
        foreach ($violationsByNode as $nodePath => $violations) {
            if (!isset($nodesByPath[$nodePath])) {
                continue;
            }

            $debt = $this->debtCalculator->calculate($violations);
            $nodesByPath[$nodePath]->debtMinutes = $debt->totalMinutes;
        }
    }

    /**
     * Computes violationCountTotal and aggregates debt bottom-up (post-order traversal).
     */
    public function aggregateBottomUp(HtmlTreeNode $node): int
    {
        $total = \count($node->violations);

        foreach ($node->children as $child) {
            $total += $this->aggregateBottomUp($child);
        }

        $node->violationCountTotal = $total;

        // Also aggregate debt bottom-up
        if ($node->children !== []) {
            $debtSum = 0;
            foreach ($node->children as $child) {
                $debtSum += $child->debtMinutes;
            }
            // Node's own debt is already set from its own violations.
            // Add children's debt.
            $node->debtMinutes += $debtSum;
        }

        return $total;
    }
}
