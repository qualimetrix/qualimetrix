<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Collection\Metric;

use LogicException;
use PhpParser\Node;
use PhpParser\NodeTraverser;
use Qualimetrix\Analysis\Collection\Dependency\DependencyVisitor;
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

    private readonly DerivedCollectorRunner $derivedCollectorRunner;

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
        $this->derivedCollectorRunner = new DerivedCollectorRunner(
            $this->derivedCollectors,
        );
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

        $traverser = new NodeTraverser();
        $this->configureTraverser($traverser, $filePath);
        $traverser->traverse($ast);

        return new CollectionOutput(
            $this->collectMetrics($file, $ast, $filePath),
            array_values($this->dependencyVisitor?->getDependencies() ?? []),
        );
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

    private function configureTraverser(NodeTraverser $traverser, ?RelativePath $filePath): void
    {
        foreach ($this->collectors as $collector) {
            $traverser->addVisitor($collector->getVisitor());
        }

        if ($this->dependencyVisitor !== null) {
            if ($filePath === null) {
                throw new LogicException('filePath is required when a dependency visitor is configured (CompositeCollector::collect)');
            }
            $this->dependencyVisitor->setFile($filePath);
            $traverser->addVisitor($this->dependencyVisitor);
        }
    }

    /** @param Node[] $ast */
    private function collectMetrics(SplFileInfo $file, array $ast, ?RelativePath $filePath): MetricBag
    {
        $result = new MetricBag();
        foreach ($this->collectors as $collector) {
            $result = $result->merge($collector->collect($file, $ast));
        }

        if ($this->derivedCollectors === []) {
            return $result;
        }
        if ($filePath === null) {
            throw new LogicException('filePath is required when derived collectors are configured (CompositeCollector::collect)');
        }

        return $this->derivedCollectorRunner->apply($result, $this->collectors, $filePath);
    }

}
