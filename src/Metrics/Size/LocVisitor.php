<?php

declare(strict_types=1);

namespace AiMessDetector\Metrics\Size;

use AiMessDetector\Metrics\ResettableVisitorInterface;
use PhpParser\NodeVisitorAbstract;

/**
 * Minimal visitor for LocCollector.
 *
 * LOC metrics are calculated directly from source code using PHP tokenizer,
 * not from AST traversal. This visitor is a no-op placeholder to satisfy
 * the MetricCollectorInterface contract.
 */
final class LocVisitor extends NodeVisitorAbstract implements ResettableVisitorInterface
{
    public function reset(): void
    {
        // No state to reset - LOC calculation is done directly from source
    }
}
