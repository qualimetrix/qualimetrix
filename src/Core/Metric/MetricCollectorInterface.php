<?php

declare(strict_types=1);

namespace AiMessDetector\Core\Metric;

use PhpParser\Node;
use PhpParser\NodeVisitorAbstract;
use SplFileInfo;

interface MetricCollectorInterface
{
    /**
     * Returns unique collector name.
     */
    public function getName(): string;

    /**
     * Returns list of metric names this collector provides.
     *
     * @return list<string>
     */
    public function provides(): array;

    /**
     * Returns metric definitions with aggregation strategies.
     *
     * Each definition describes how the metric should be aggregated
     * at higher symbol levels (class → namespace → project).
     *
     * @return list<MetricDefinition>
     */
    public function getMetricDefinitions(): array;

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
