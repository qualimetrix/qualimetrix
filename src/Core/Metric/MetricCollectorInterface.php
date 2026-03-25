<?php

declare(strict_types=1);

namespace Qualimetrix\Core\Metric;

use PhpParser\Node;
use PhpParser\NodeVisitorAbstract;
use SplFileInfo;

interface MetricCollectorInterface extends BaseCollectorInterface
{
    /**
     * Returns the visitor for AST traversal.
     */
    public function getVisitor(): NodeVisitorAbstract;

    /**
     * Collects metrics from AST after traversal.
     *
     * @param Node[] $ast
     */
    public function collect(SplFileInfo $file, array $ast): MetricBag;

    /**
     * Resets visitor state between files.
     */
    public function reset(): void;
}
