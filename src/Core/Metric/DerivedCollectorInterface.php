<?php

declare(strict_types=1);

namespace AiMessDetector\Core\Metric;

/**
 * Collector that derives metrics from other collectors' results.
 *
 * Derived collectors are executed AFTER all regular collectors complete,
 * in a separate phase. They calculate composite metrics from base metrics.
 *
 * Example: Maintainability Index combines Halstead Volume, CCN, and LOC.
 */
interface DerivedCollectorInterface
{
    /**
     * Returns unique collector name.
     */
    public function getName(): string;

    /**
     * Returns names of collectors this derived collector depends on.
     *
     * The derived collector can only be executed after all required
     * collectors have provided their metrics.
     *
     * @return list<string> Names of required collectors
     */
    public function requires(): array;

    /**
     * Calculates derived metrics from source metrics.
     *
     * Called for each method/symbol after base metrics are collected.
     * Should return a MetricBag with calculated derived metrics.
     *
     * @param MetricBag $sourceBag Contains base metrics from required collectors
     *
     * @return MetricBag New bag with derived metrics
     */
    public function calculate(MetricBag $sourceBag): MetricBag;

    /**
     * Returns metric definitions for derived metrics.
     *
     * @return list<MetricDefinition>
     */
    public function getMetricDefinitions(): array;

    /**
     * Returns list of metric names this collector provides.
     *
     * @return list<string>
     */
    public function provides(): array;
}
