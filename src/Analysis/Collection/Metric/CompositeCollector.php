<?php

declare(strict_types=1);

namespace AiMessDetector\Analysis\Collection\Metric;

use AiMessDetector\Analysis\Collection\Dependency\DependencyVisitor;
use AiMessDetector\Core\Metric\DerivedCollectorInterface;
use AiMessDetector\Core\Metric\MetricBag;
use AiMessDetector\Core\Metric\MetricCollectorInterface;
use PhpParser\Node;
use PhpParser\NodeTraverser;
use SplFileInfo;
use Traversable;

final class CompositeCollector
{
    /** @var list<MetricCollectorInterface> */
    private readonly array $collectors;

    /** @var list<DerivedCollectorInterface> */
    private readonly array $derivedCollectors;

    /**
     * Optional dependency visitor to collect dependencies in the same traversal.
     */
    private ?DependencyVisitor $dependencyVisitor = null;

    /**
     * @param iterable<MetricCollectorInterface> $collectors
     * @param iterable<DerivedCollectorInterface> $derivedCollectors
     */
    public function __construct(iterable $collectors, iterable $derivedCollectors = [])
    {
        $this->collectors = $collectors instanceof Traversable
            ? iterator_to_array($collectors, false)
            : array_values($collectors);

        $this->derivedCollectors = $derivedCollectors instanceof Traversable
            ? iterator_to_array($derivedCollectors, false)
            : array_values($derivedCollectors);
    }

    /**
     * Sets the dependency visitor to use during collection.
     *
     * When set, dependencies will be collected during the same AST traversal
     * as metrics, eliminating the need for a separate dependency pass.
     */
    public function setDependencyVisitor(?DependencyVisitor $visitor): void
    {
        $this->dependencyVisitor = $visitor;
    }

    /**
     * Returns the current dependency visitor.
     */
    public function getDependencyVisitor(): ?DependencyVisitor
    {
        return $this->dependencyVisitor;
    }

    /**
     * Collects metrics and optionally dependencies via single AST traversal.
     *
     * @param Node[] $ast
     */
    public function collect(SplFileInfo $file, array $ast): CollectionOutput
    {
        if ($this->collectors === [] && $this->dependencyVisitor === null) {
            return new CollectionOutput(new MetricBag(), []);
        }

        // Create traverser with all visitors
        $traverser = new NodeTraverser();

        foreach ($this->collectors as $collector) {
            $traverser->addVisitor($collector->getVisitor());
        }

        // Add dependency visitor if configured
        if ($this->dependencyVisitor !== null) {
            $this->dependencyVisitor->setFile($file->getPathname());
            $traverser->addVisitor($this->dependencyVisitor);
        }

        // Single AST traversal for both metrics and dependencies
        $traverser->traverse($ast);

        // Collect and merge metrics from all collectors
        $result = new MetricBag();

        foreach ($this->collectors as $collector) {
            $metrics = $collector->collect($file, $ast);
            $result = $result->merge($metrics);
        }

        // Apply derived collectors
        if ($this->derivedCollectors !== []) {
            $result = $this->applyDerivedCollectors($result);
        }

        // Collect dependencies
        $dependencies = $this->dependencyVisitor?->getDependencies() ?? [];

        return new CollectionOutput($result, array_values($dependencies));
    }

    /**
     * Resets all collectors between files.
     */
    public function reset(): void
    {
        foreach ($this->collectors as $collector) {
            $collector->reset();
        }
    }

    /**
     * @return list<MetricCollectorInterface>
     */
    public function getCollectors(): array
    {
        return $this->collectors;
    }

    /**
     * @return list<DerivedCollectorInterface>
     */
    public function getDerivedCollectors(): array
    {
        return $this->derivedCollectors;
    }

    /**
     * Applies derived collectors to compute derived metrics.
     *
     * Derived collectors are sorted topologically based on their requires()/provides()
     * dependencies, and results are accumulated so each collector can see outputs
     * of previously executed collectors.
     *
     * Optimized: indexes metrics by FQN in a single pass (O(M)),
     * then applies derived collectors for each FQN (O(N × K)).
     * Total complexity: O(M + N × K) instead of O(N × M × K).
     */
    private function applyDerivedCollectors(MetricBag $baseBag): MetricBag
    {
        // Index metrics by FQN in single pass — O(M)
        $metricsByFqn = $this->indexMetricsByFqn($baseBag);

        if ($metricsByFqn === []) {
            return $baseBag;
        }

        // Sort derived collectors topologically so each can see previous outputs
        $sortedCollectors = $this->sortDerivedCollectors($this->derivedCollectors);

        $result = $baseBag;

        // Apply derived collectors for each FQN — O(N × K)
        // Accumulate results so each derived collector can see previous outputs
        foreach ($metricsByFqn as $fqn => $workingMetrics) {
            foreach ($sortedCollectors as $derivedCollector) {
                $derivedMetrics = $derivedCollector->calculate($workingMetrics);

                // Accumulate into working metrics so next collector can see these results
                $workingMetrics = $workingMetrics->merge($derivedMetrics);

                foreach ($derivedMetrics->all() as $name => $value) {
                    $result = $result->with($name . ':' . $fqn, $value);
                }
            }
        }

        return $result;
    }

    /**
     * Sorts derived collectors topologically using Kahn's algorithm.
     *
     * @param list<DerivedCollectorInterface> $collectors
     *
     * @return list<DerivedCollectorInterface>
     */
    private function sortDerivedCollectors(array $collectors): array
    {
        if (\count($collectors) <= 1) {
            return $collectors;
        }

        // Build mapping: collector name → collector index
        $indexByName = [];
        foreach ($collectors as $index => $collector) {
            $indexByName[$collector->getName()] = $index;
        }

        // Build mapping: provided metric name → collector name
        $providers = [];
        foreach ($collectors as $collector) {
            $name = $collector->getName();
            foreach ($collector->provides() as $metric) {
                $providers[$metric] = $name;
            }
        }

        // Calculate in-degree and build dependency graph
        $inDegree = array_fill(0, \count($collectors), 0);
        $dependents = array_fill(0, \count($collectors), []);

        foreach ($collectors as $index => $collector) {
            $seen = [];
            foreach ($collector->requires() as $required) {
                // requires() returns collector names, not metric names
                if (isset($indexByName[$required]) && !isset($seen[$required])) {
                    $seen[$required] = true;
                    $providerIndex = $indexByName[$required];
                    $inDegree[$index]++;
                    $dependents[$providerIndex][] = $index;
                }
            }
        }

        // Kahn's algorithm
        $queue = [];
        foreach ($inDegree as $index => $degree) {
            if ($degree === 0) {
                $queue[] = $index;
            }
        }

        $sorted = [];
        while ($queue !== []) {
            $current = array_shift($queue);
            $sorted[] = $collectors[$current];

            foreach ($dependents[$current] as $dependentIndex) {
                $inDegree[$dependentIndex]--;
                if ($inDegree[$dependentIndex] === 0) {
                    $queue[] = $dependentIndex;
                }
            }
        }

        // If not all sorted, fall back to original order (cycle — shouldn't happen)
        if (\count($sorted) !== \count($collectors)) {
            return $collectors;
        }

        return $sorted;
    }

    /**
     * Indexes all metrics by FQN in a single pass.
     *
     * Metric keys are in format: metricName:fqn
     * Returns array of FQN => MetricBag with base metric names.
     *
     * @return array<string, MetricBag>
     */
    private function indexMetricsByFqn(MetricBag $bag): array
    {
        /** @var array<string, array<string, int|float>> $byFqn */
        $byFqn = [];

        foreach ($bag->all() as $key => $value) {
            $colonPos = strpos($key, ':');
            if ($colonPos === false) {
                continue;
            }

            $metricName = substr($key, 0, $colonPos);
            $fqn = substr($key, $colonPos + 1);

            $byFqn[$fqn][$metricName] = $value;
        }

        // Convert arrays to MetricBags
        $result = [];
        foreach ($byFqn as $fqn => $metrics) {
            $result[$fqn] = MetricBag::fromArray($metrics);
        }

        return $result;
    }
}
