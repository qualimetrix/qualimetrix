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

        $result = $baseBag;

        // Apply derived collectors for each FQN — O(N × K)
        foreach ($metricsByFqn as $fqn => $methodMetrics) {
            foreach ($this->derivedCollectors as $derivedCollector) {
                $derivedMetrics = $derivedCollector->calculate($methodMetrics);

                foreach ($derivedMetrics->all() as $name => $value) {
                    $result = $result->with($name . ':' . $fqn, $value);
                }
            }
        }

        return $result;
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
