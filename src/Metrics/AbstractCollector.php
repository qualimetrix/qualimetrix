<?php

declare(strict_types=1);

namespace Qualimetrix\Metrics;

use PhpParser\Node;
use PhpParser\NodeVisitorAbstract;
use Qualimetrix\Core\Metric\MetricBag;
use Qualimetrix\Core\Metric\MetricCollectorInterface;
use Qualimetrix\Core\Metric\MetricDefinition;
use Qualimetrix\Core\Metric\ParallelSafeCollectorInterface;
use SplFileInfo;

/**
 * Base class for metric collectors.
 *
 * Provides common functionality for stateful-per-file collectors:
 * - Holds visitor instance
 * - Implements reset() by delegating to visitor
 */
abstract class AbstractCollector implements MetricCollectorInterface, ParallelSafeCollectorInterface
{
    protected NodeVisitorAbstract $visitor;

    public function getVisitor(): NodeVisitorAbstract
    {
        return $this->visitor;
    }

    public function reset(): void
    {
        if ($this->visitor instanceof ResettableVisitorInterface) {
            $this->visitor->reset();
        }
    }

    /**
     * @param Node[] $ast
     */
    abstract public function collect(SplFileInfo $file, array $ast): MetricBag;

    abstract public function getName(): string;

    /**
     * @return list<string>
     */
    abstract public function provides(): array;

    /**
     * Returns metric definitions with aggregation strategies.
     *
     * Default implementation returns empty array for backward compatibility.
     * Override in concrete collectors to enable self-aggregating metrics.
     *
     * @return list<MetricDefinition>
     */
    public function getMetricDefinitions(): array
    {
        return [];
    }
}
