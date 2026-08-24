<?php

declare(strict_types=1);

namespace Qualimetrix\Reporting\Formatter\Html;

use Qualimetrix\Analysis\Evidence\Prioritization\Debt\DebtCalculator;
use Qualimetrix\Analysis\Finding\Contract\Finding;

/**
 * Computes and aggregates technical debt for HTML tree nodes.
 *
 * Assigns debt from partitioned findings to leaf nodes, then aggregates
 * debt and finding counts bottom-up through the tree hierarchy.
 *
 * @internal
 */
final readonly class HtmlDebtCalculator
{
    public function __construct(
        private DebtCalculator $debtCalculator,
    ) {}

    /**
     * Computes debt per node from partitioned findings.
     *
     * @param array<string, list<Finding>> $findingsByNode
     * @param array<string, HtmlTreeNode> $nodesByPath
     */
    public function computeDebt(
        array $findingsByNode,
        array $nodesByPath,
    ): void {
        foreach ($findingsByNode as $nodePath => $findings) {
            if (!isset($nodesByPath[$nodePath])) {
                continue;
            }

            $debt = $this->debtCalculator->calculate($findings);
            $nodesByPath[$nodePath]->debtMinutes = $debt->totalMinutes;
        }
    }

    /**
     * Computes violationCountTotal and aggregates debt bottom-up (post-order traversal).
     */
    public function aggregateBottomUp(HtmlTreeNode $node): int
    {
        $total = \count($node->findings);

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
            // Node's own debt is already set from its own findings.
            // Add children's debt.
            $node->debtMinutes += $debtSum;
        }

        return $total;
    }
}
