<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Measurement\Aggregation;

use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricDefinition;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricRepositoryInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\NamespaceTree;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\SymbolLevel;
use Qualimetrix\Core\Profiler\ProfilerHolder;

/**
 * Aggregates metrics from lower levels (Callable, File) to higher levels (Class, Namespace, Project).
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

    private function hasCallableLevelDefinitions(): bool
    {
        foreach ($this->definitions as $def) {
            if ($def->collectedAt === SymbolLevel::Callable && $def->hasAggregationsForLevel(SymbolLevel::Class_)) {
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
    public function aggregate(MetricRepositoryInterface $repository, ?NamespaceTree $existingTree = null): NamespaceTree
    {
        if ($this->definitions === []) {
            return $existingTree ?? new NamespaceTree([]);
        }

        $profiler = ProfilerHolder::get();

        // Skip callable→class phase when no callable-level definitions exist
        // (e.g., during re-aggregation of global collector metrics).
        if ($this->hasCallableLevelDefinitions()) {
            $profiler->start('aggregation.callables_to_classes', 'aggregation');
            (new CallableToClassAggregator())->aggregate($repository, $this->definitions);
            $profiler->stop('aggregation.callables_to_classes');
        }

        // Class→namespace aggregation: runs even during re-aggregation because
        // global collectors add new class-level metrics that need namespace rollup.
        $profiler->start('aggregation.to_namespaces', 'aggregation');
        (new ClassToNamespaceAggregator())->aggregate($repository, $this->definitions);
        $profiler->stop('aggregation.to_namespaces');

        // Reuse pre-built tree when available (re-aggregation pass) to avoid
        // rebuilding from contaminated repository that already contains parent namespaces.
        $tree = $existingTree ?? new NamespaceTree($repository->getNamespaces());

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
