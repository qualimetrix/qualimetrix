<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Measurement\FileMeasurement;

use PhpParser\Node;
use PhpParser\NodeTraverser;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyTraversalParticipantInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\CollectionOutput;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\DeclarationIndexAwareInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\DeclarationRegistrarFactory;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\DerivedCollectorInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\FileMeasurementCollectorInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricBag;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricCollectorInterface;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\FileDeclarationIndex;
use SplFileInfo;
use Traversable;

final class CompositeCollector implements FileMeasurementCollectorInterface
{
    /** @var list<MetricCollectorInterface> */
    private readonly array $collectors;

    /** @var list<DerivedCollectorInterface> */
    private readonly array $derivedCollectors;

    private readonly DerivedCollectorRunner $derivedCollectorRunner;

    /**
     * The registrar factory is required, not optional: this constructor is also
     * reached positionally by the parallel worker bootstrap, where a dependency
     * that may be omitted would produce workers that silently number files
     * without a registrar.
     *
     * @param iterable<MetricCollectorInterface> $collectors
     * @param iterable<DerivedCollectorInterface> $derivedCollectors
     */
    public function __construct(
        iterable $collectors,
        private readonly DeclarationRegistrarFactory $declarationRegistrarFactory,
        iterable $derivedCollectors = [],
        private readonly ?DependencyTraversalParticipantInterface $dependencyTraversalParticipant = null,
    ) {
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
     * Collects metrics and optionally dependencies via single AST traversal.
     *
     * @param Node[] $ast
     * @param RelativePath $filePath Project-relative path for dependency-visitor
     *                               keying. Caller (FileProcessor) computes this once
     *                               so projectRoot threading stays at the boundary.
     */
    public function collect(SplFileInfo $file, array $ast, RelativePath $filePath): CollectionOutput
    {
        if ($this->collectors === [] && $this->dependencyTraversalParticipant === null) {
            return new CollectionOutput(new MetricBag(), []);
        }

        $traverser = new NodeTraverser();
        $this->configureTraverser($traverser, $filePath);
        $traverser->traverse($ast);

        return new CollectionOutput(
            $this->collectMetrics($file, $ast, $filePath),
            $this->dependencyTraversalParticipant?->dependencies() ?? [],
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

    /**
     * The registrar goes in first so that every producer asking about the node
     * it is entering finds that node already registered.
     */
    private function configureTraverser(NodeTraverser $traverser, RelativePath $filePath): void
    {
        $registrar = $this->declarationRegistrarFactory->createForFile();
        $traverser->addVisitor($registrar);
        $index = $registrar->index();

        foreach ($this->collectors as $collector) {
            $visitor = $collector->getVisitor();
            self::deliverDeclarationIndex($collector, $index);
            self::deliverDeclarationIndex($visitor, $index);
            $traverser->addVisitor($visitor);
        }

        if ($this->dependencyTraversalParticipant !== null) {
            $this->dependencyTraversalParticipant->beginFile($filePath, $index);
            $traverser->addVisitor($this->dependencyTraversalParticipant);
        }
    }

    private static function deliverDeclarationIndex(object $participant, FileDeclarationIndex $index): void
    {
        if ($participant instanceof DeclarationIndexAwareInterface) {
            $participant->useDeclarationIndex($index);
        }
    }

    /** @param Node[] $ast */
    private function collectMetrics(SplFileInfo $file, array $ast, RelativePath $filePath): MetricBag
    {
        $result = new MetricBag();
        foreach ($this->collectors as $collector) {
            $result = $result->merge($collector->collect($file, $ast));
        }

        if ($this->derivedCollectors === []) {
            return $result;
        }
        return $this->derivedCollectorRunner->apply($result, $this->collectors, $filePath);
    }

}
