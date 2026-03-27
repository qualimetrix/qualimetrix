<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Aggregator;

use Qualimetrix\Core\Metric\MetricDefinition;
use Qualimetrix\Core\Metric\MetricRepositoryInterface;
use Qualimetrix\Core\Metric\SymbolLevel;
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
     */
    public function aggregate(MetricRepositoryInterface $repository): void
    {
        if ($this->definitions === []) {
            return;
        }

        $profiler = ProfilerHolder::get();

        // Skip method→class phase when no method-level definitions exist
        // (e.g., during re-aggregation of global collector metrics).
        $phases = [];
        if ($this->hasMethodLevelDefinitions()) {
            $phases['aggregation.methods_to_classes'] = new MethodToClassAggregator();
        }
        $phases['aggregation.to_namespaces'] = new ClassToNamespaceAggregator();
        $phases['aggregation.namespace_hierarchy'] = new NamespaceHierarchyAggregator();
        $phases['aggregation.to_project'] = new NamespaceToProjectAggregator();

        foreach ($phases as $spanName => $phase) {
            $profiler->start($spanName, 'aggregation');
            $phase->aggregate($repository, $this->definitions);
            $profiler->stop($spanName);
        }
    }
}
