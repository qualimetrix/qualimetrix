<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Measurement\Contract;

use PhpParser\Node;
use PhpParser\NodeVisitorAbstract;
use SplFileInfo;

interface MetricCollectorInterface
{
    public function getName(): string;

    /** @return list<string> */
    public function provides(): array;

    /** @return list<MetricDefinition> */
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
