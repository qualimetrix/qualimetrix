<?php

declare(strict_types=1);

namespace Qualimetrix\Core\Metric;

use Qualimetrix\Core\Dependency\DependencyGraphInterface;

/**
 * Interface for collectors that compute metrics from global context.
 *
 * Unlike MetricCollectorInterface which operates on individual files via AST,
 * GlobalContextCollector operates on already-collected metrics and the dependency graph.
 * This allows computing metrics that require cross-file knowledge (coupling, distance, etc.)
 *
 * Collectors declare their dependencies via requires() and are executed in topological order.
 */
interface GlobalContextCollectorInterface extends BaseCollectorInterface
{
    /**
     * Returns list of metric names this collector requires.
     *
     * These metrics must be already computed before this collector runs.
     * Used for topological sorting of collectors.
     *
     * @return list<string>
     */
    public function requires(): array;

    /**
     * Computes metrics based on the dependency graph and existing metrics.
     *
     * Called once after all per-file metrics have been collected and aggregated.
     * The collector should update the repository with computed metrics.
     *
     * IMPORTANT: Collectors must only enrich symbols that already exist in the repository.
     * They must NOT create new symbols (e.g. for vendor/external classes discovered
     * in the dependency graph). The repository is pre-populated during the collection
     * phase with project symbols only. Use $repository->has() to verify a symbol exists
     * before adding metrics for it when iterating external data sources like the
     * dependency graph.
     *
     * @param DependencyGraphInterface $graph The dependency graph
     * @param MetricRepositoryInterface $repository Repository with existing metrics
     */
    public function calculate(
        DependencyGraphInterface $graph,
        MetricRepositoryInterface $repository,
    ): void;
}
