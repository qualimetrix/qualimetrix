<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Aggregator;

use Qualimetrix\Core\Dependency\DependencyGraphInterface;
use Qualimetrix\Core\Metric\GlobalContextCollectorInterface;
use Qualimetrix\Core\Metric\MetricRepositoryInterface;

/**
 * Runs global context collectors in topological order.
 *
 * Global collectors compute metrics that require cross-file knowledge,
 * such as coupling, distance from main sequence, etc. They are executed
 * after per-file metrics have been collected and aggregated.
 */
final class GlobalCollectorRunner
{
    /** @var list<GlobalContextCollectorInterface> */
    private readonly array $sortedCollectors;

    /**
     * @param iterable<GlobalContextCollectorInterface> $collectors
     */
    public function __construct(iterable $collectors)
    {
        $sorter = new GlobalCollectorSorter();
        $this->sortedCollectors = $sorter->sort($collectors);
    }

    /**
     * Runs all global collectors on the given graph and repository.
     *
     * Collectors are executed in topological order, ensuring that
     * each collector's required metrics are already computed.
     *
     * @param DependencyGraphInterface $graph The dependency graph
     * @param MetricRepositoryInterface $repository Repository with existing metrics
     */
    public function run(DependencyGraphInterface $graph, MetricRepositoryInterface $repository): void
    {
        foreach ($this->sortedCollectors as $collector) {
            $collector->calculate($graph, $repository);
        }
    }

    /**
     * Returns the number of collectors.
     */
    public function count(): int
    {
        return \count($this->sortedCollectors);
    }

    /**
     * Returns whether there are any collectors.
     */
    public function hasCollectors(): bool
    {
        return $this->sortedCollectors !== [];
    }

    /**
     * Returns all sorted collectors.
     *
     * @return list<GlobalContextCollectorInterface>
     */
    public function getCollectors(): array
    {
        return $this->sortedCollectors;
    }
}
