<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Collection\Metric;

use LogicException;
use PhpParser\Node;
use PhpParser\NodeTraverser;
use Qualimetrix\Analysis\Collection\Dependency\DependencyVisitor;
use Qualimetrix\Core\Metric\CallableWithMetrics;
use Qualimetrix\Core\Metric\DerivedCollectorInterface;
use Qualimetrix\Core\Metric\MetricBag;
use Qualimetrix\Core\Metric\MetricCollectorInterface;
use Qualimetrix\Core\Path\RelativePath;
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
     * @param RelativePath|null $filePath Project-relative path for dependency-visitor
     *                                    keying. May be null only when no dependency
     *                                    visitor is configured. Caller (FileProcessor)
     *                                    computes this once so projectRoot threading
     *                                    stays at the boundary.
     */
    public function collect(SplFileInfo $file, array $ast, ?RelativePath $filePath = null): CollectionOutput
    {
        if ($this->collectors === [] && $this->dependencyVisitor === null) {
            return new CollectionOutput(new MetricBag(), []);
        }

        // Create traverser with all visitors
        $traverser = new NodeTraverser();

        foreach ($this->collectors as $collector) {
            $traverser->addVisitor($collector->getVisitor());
        }

        // Add dependency visitor if configured. The file path comes pre-relativized
        // from FileProcessor (same key shape used for `filePath` and the per-file
        // suppression dict). Without this, dependency-derived violations
        // (e.g. `architecture.layer-violation`) carry an absolute path that never
        // matches the relative-keyed suppression map, so `@qmx-ignore` tags on
        // classes can't drop them.
        if ($this->dependencyVisitor !== null) {
            if ($filePath === null) {
                throw new LogicException(
                    'filePath is required when a dependency visitor is configured (CompositeCollector::collect)',
                );
            }
            $this->dependencyVisitor->setFile($filePath);
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
            if ($filePath === null) {
                throw new LogicException('filePath is required when derived collectors are configured (CompositeCollector::collect)');
            }

            $result = $this->applyDerivedCollectors($result, $filePath);
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
    private function applyDerivedCollectors(MetricBag $baseBag, RelativePath $file): MetricBag
    {
        // Validate the dependency graph even when this file has no callables.
        $sortedCollectors = $this->sortDerivedCollectors($this->derivedCollectors);
        $metricsByDeclaration = $this->callableMetricsByDeclaration($file);

        if ($metricsByDeclaration === []) {
            return $baseBag;
        }

        $result = $baseBag;

        // Apply derived collectors for each exact declaration — O(N × K)
        // Accumulate results so each derived collector can see previous outputs
        foreach ($metricsByDeclaration as $callable) {
            $workingMetrics = $callable->metrics;
            foreach ($sortedCollectors as $derivedCollector) {
                $derivedMetrics = $derivedCollector->calculate($workingMetrics);

                // Accumulate into working metrics so next collector can see these results
                $workingMetrics = $workingMetrics->merge($derivedMetrics);

                foreach ($derivedMetrics->all() as $name => $value) {
                    $result = $result->with($this->derivedMetricKey($name, $callable), $value);
                }
            }
        }

        return $result;
    }

    /** @return array<string, CallableWithMetrics> */
    private function callableMetricsByDeclaration(RelativePath $file): array
    {
        $callables = [];

        foreach ($this->collectors as $collector) {
            if (!$collector instanceof \Qualimetrix\Core\Metric\CallableMetricsProviderInterface) {
                continue;
            }

            foreach ($collector->getCallablesWithMetrics($file) as $callable) {
                $key = $callable->kind->value . ':' . $callable->declarationPath->toCanonical();
                $existing = $callables[$key] ?? null;
                $callables[$key] = $existing === null
                    ? $callable
                    : new CallableWithMetrics(
                        $existing->declarationPath,
                        $existing->kind,
                        $existing->anonymousSyntax,
                        $existing->lexicalClassContext,
                        $existing->classAggregationOwner,
                        $existing->metrics->merge($callable->metrics),
                        $existing->sourceLine,
                    );
            }
        }

        return $callables;
    }

    private function derivedMetricKey(string $metricName, CallableWithMetrics $callable): string
    {
        return $metricName . ':' . $callable->kind->value . ':' . $callable->declarationPath->toCanonical();
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

        // If not all sorted, there is a cyclic dependency
        if (\count($sorted) !== \count($collectors)) {
            $unsorted = array_filter(
                $collectors,
                static fn(DerivedCollectorInterface $c): bool => !\in_array($c, $sorted, true),
            );
            $names = array_map(
                static fn(DerivedCollectorInterface $c): string => $c->getName(),
                array_values($unsorted),
            );

            throw new LogicException(\sprintf(
                'Cyclic dependency detected between derived collectors: %s',
                implode(', ', $names),
            ));
        }

        return $sorted;
    }

}
