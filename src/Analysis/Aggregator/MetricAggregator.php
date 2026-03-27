<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Aggregator;

use Qualimetrix\Core\Metric\MetricDefinition;
use Qualimetrix\Core\Metric\MetricRepositoryInterface;
use Qualimetrix\Core\Metric\SymbolLevel;
use Qualimetrix\Core\Namespace_\NamespaceTree;
use Qualimetrix\Core\Profiler\ProfilerHolder;

/**
 * Aggregates metrics from lower levels (Method, File) to higher levels (Class, Namespace, Project).
 *
 * Uses MetricDefinitions to determine which aggregation strategies to apply.
 * No hardcoded metric names — fully generic.
 */
final class MetricAggregator
{
    /**
     * @param list<MetricDefinition> $definitions
     */
    public function __construct(private readonly array $definitions) {}

    private function hasMethodLevelDefinitions(): bool
    {
        foreach ($this->definitions as $def) {
            if ($def->collectedAt === SymbolLevel::Method && $def->hasAggregationsForLevel(SymbolLevel::Class_)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Aggregates metrics and stores results in the repository.
     *
     * Returns a NamespaceTree built from leaf namespaces discovered during aggregation.
     */
    public function aggregate(MetricRepositoryInterface $repository): NamespaceTree
    {
        if ($this->definitions === []) {
            return new NamespaceTree([]);
        }

        $profiler = ProfilerHolder::get();

        // Skip method→class phase when no method-level definitions exist
        // (e.g., during re-aggregation of global collector metrics).
        if ($this->hasMethodLevelDefinitions()) {
            $profiler->start('aggregation.methods_to_classes', 'aggregation');
            (new MethodToClassAggregator())->aggregate($repository, $this->definitions);
            $profiler->stop('aggregation.methods_to_classes');
        }

        // Class→namespace aggregation: after this phase, repository contains leaf namespaces
        $profiler->start('aggregation.to_namespaces', 'aggregation');
        (new ClassToNamespaceAggregator())->aggregate($repository, $this->definitions);
        $profiler->stop('aggregation.to_namespaces');

        // Build the namespace tree from leaf namespaces
        $tree = new NamespaceTree($repository->getNamespaces());

        // Namespace hierarchy: aggregate leaf metrics into parent namespaces
        $profiler->start('aggregation.namespace_hierarchy', 'aggregation');
        (new TreeAwareNamespaceAggregator($tree))->aggregate($repository, $this->definitions);
        $profiler->stop('aggregation.namespace_hierarchy');

        // Project-level aggregation
        $profiler->start('aggregation.to_project', 'aggregation');
        (new NamespaceToProjectAggregator($tree))->aggregate($repository, $this->definitions);
        $profiler->stop('aggregation.to_project');

        return $tree;
    }
}
