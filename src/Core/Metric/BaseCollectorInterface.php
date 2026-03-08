<?php

declare(strict_types=1);

namespace AiMessDetector\Core\Metric;

/**
 * Base interface for all metric collectors.
 *
 * Defines the common contract shared by all collector types:
 * - MetricCollectorInterface (AST-based, per-file)
 * - DerivedCollectorInterface (computed from other metrics)
 * - GlobalContextCollectorInterface (cross-file, dependency graph)
 */
interface BaseCollectorInterface
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
     * at higher symbol levels (class -> namespace -> project).
     *
     * @return list<MetricDefinition>
     */
    public function getMetricDefinitions(): array;
}
