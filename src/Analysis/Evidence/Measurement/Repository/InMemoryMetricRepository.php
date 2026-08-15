<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Measurement\Repository;

use InvalidArgumentException;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\CallableWithMetrics;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricBag;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricRepositoryInterface;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\MetricSubject;
use Qualimetrix\Core\Symbol\SymbolInfo;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Core\Symbol\SymbolType;

final class InMemoryMetricRepository implements MetricRepositoryInterface
{
    public function mergedWith(MetricRepositoryInterface $other): ?MetricRepositoryInterface
    {
        if (!$other instanceof self) {
            return null;
        }

        return $this->mergeWith($other);
    }

    /** @var array<string, MetricBag> canonical -> MetricBag */
    private array $metrics = [];

    /** @var array<string, SymbolInfo> canonical -> SymbolInfo */
    private array $symbolInfos = [];

    private MetricSubjectIndex $subjectIndex;

    private NamespaceMetricIndex $namespaceIndex;

    public function __construct()
    {
        $this->subjectIndex = new MetricSubjectIndex();
        $this->namespaceIndex = new NamespaceMetricIndex();
    }

    public function get(SymbolPath $symbol): MetricBag
    {
        $canonical = $symbol->toCanonical();

        if (isset($this->metrics[$canonical])) {
            return $this->metrics[$canonical];
        }

        return $symbol->getType() === SymbolType::Class_
            ? $this->subjectIndex->logicalClassMetrics($symbol) ?? new MetricBag()
            : $this->subjectIndex->logicalCallableMetrics($canonical) ?? new MetricBag();
    }

    public function all(SymbolType $type): iterable
    {
        $seen = [];
        foreach ($this->symbolInfos as $canonical => $info) {
            if ($info->symbolPath->getType() === $type) {
                $seen[$canonical] = true;
                yield $info;
            }
        }

        if ($type === SymbolType::Class_) {
            yield from $this->unseenLogicalClasses($seen);
        }

        if (\in_array($type, [SymbolType::Method, SymbolType::Function_], true)) {
            yield from $this->callablesOfType($type);
        }
    }

    public function has(SymbolPath $symbol): bool
    {
        $canonical = $symbol->toCanonical();

        if (isset($this->metrics[$canonical])) {
            return true;
        }

        return $symbol->getType() === SymbolType::Class_
            ? $this->subjectIndex->logicalClassMetrics($symbol) !== null
            : $this->subjectIndex->logicalCallableMetrics($canonical) !== null;
    }

    /**
     * Adds or merges metrics for a symbol.
     *
     * If the symbol already has metrics, new metrics are merged (new values override).
     */
    public function add(SymbolPath $symbol, MetricBag $metrics, ?RelativePath $file, ?int $line): void
    {
        if (\in_array($symbol->getType(), [SymbolType::Method, SymbolType::Function_], true)) {
            throw new InvalidArgumentException('MetricRepositoryInterface::add() accepts aggregate or logical-class SymbolPath only; use addCallable() or addSubject() for declarations');
        }

        if ($symbol->getType() === SymbolType::Class_) {
            $info = $this->subjectIndex->addLogicalClass($symbol, $metrics, $file, $line === 0 ? null : $line);
            $this->namespaceIndex->add($info);

            return;
        }

        $canonical = $symbol->toCanonical();

        if (isset($this->metrics[$canonical])) {
            // Merge with existing metrics
            $this->metrics[$canonical] = $this->metrics[$canonical]->merge($metrics);

            $this->symbolInfos[$canonical] = RepositoryMerge::plainInfo(
                $this->symbolInfos[$canonical],
                new SymbolInfo($symbol, $file, $line),
            );
        } else {
            $this->metrics[$canonical] = $metrics;
            $info = new SymbolInfo($symbol, $file, $line);
            $this->symbolInfos[$canonical] = $info;

        }

        $this->namespaceIndex->add($this->symbolInfos[$canonical]);
        $this->subjectIndex->synchronizeAggregateInfo($this->symbolInfos[$canonical]);
    }

    public function getSubject(MetricSubject $subject): MetricBag
    {
        if ($subject->aggregatePath() !== null) {
            return $this->get($subject->aggregatePath());
        }

        return $this->subjectIndex->get($subject);
    }

    public function hasSubject(MetricSubject $subject): bool
    {
        if ($subject->aggregatePath() !== null) {
            return $this->has($subject->aggregatePath());
        }

        return $this->subjectIndex->has($subject);
    }

    public function addSubject(MetricSubject $subject, MetricBag $metrics, ?RelativePath $file, ?int $line): void
    {
        $aggregate = $subject->aggregatePath();
        if ($aggregate !== null) {
            $this->subjectIndex->add($subject, new MetricBag(), $file, $line === 0 ? null : $line);
            $this->add($aggregate, $metrics, $file, $line);

            return;
        }

        // Keep an unknown location as null; Location explicitly rejects
        // synthetic 0.
        $line = $line === 0 ? null : $line;
        $info = $this->subjectIndex->add($subject, $metrics, $file, $line);

        $declaration = $subject->declarationPath();
        if ($declaration?->logical->getType() === SymbolType::Class_) {
            // Exact class facts remain addressable by DeclarationPath. Their
            // logical projection is the separate class-facing view used by
            // aggregation, graph construction, and legacy SymbolPath reads.
            // Do not index the declaration itself: it would count alongside
            // its projection during namespace aggregation.
            $this->addLogicalClassProjection($declaration->logical, $metrics);

            return;
        }

        $this->namespaceIndex->add($info);
    }

    public function addCallable(CallableWithMetrics $callable): void
    {
        $info = $this->subjectIndex->addCallable($callable);
        $this->namespaceIndex->add($info);

        if ($callable->classAggregationOwner !== null) {
            $this->addLogicalClassProjection($callable->classAggregationOwner->symbolPath, new MetricBag());
        }
    }

    public function allDeclarations(): iterable
    {
        yield from $this->subjectIndex->allDeclarations();
    }

    public function allCallables(): iterable
    {
        yield from $this->subjectIndex->allCallables();
    }

    public function allLogicalClasses(): iterable
    {
        yield from $this->subjectIndex->allLogicalClasses();
    }

    public function addScalar(SymbolPath $symbol, string $key, int|float $value): void
    {
        $canonical = $symbol->toCanonical();

        if ($symbol->getType() === SymbolType::Class_) {
            $this->subjectIndex->addLogicalClassScalar($symbol, $key, $value);

            return;
        }

        if (!isset($this->metrics[$canonical])) {
            return;
        }

        $this->metrics[$canonical] = $this->metrics[$canonical]->with($key, $value);
    }

    /**
     * Returns all namespaces that have metrics.
     *
     * @return list<string>
     */
    public function getNamespaces(): array
    {
        return $this->namespaceIndex->namespaces();
    }

    /**
     * Returns all metrics for symbols in a given namespace.
     *
     * @return list<SymbolInfo>
     */
    public function forNamespace(string $namespace): array
    {
        return $this->namespaceIndex->forNamespace($namespace);
    }

    /**
     * Creates a new repository with metrics merged from both repositories.
     *
     * If both repositories have metrics for the same symbol, they are merged.
     */
    public function mergeWith(self $other): self
    {
        $merged = new self();
        $plain = RepositoryMerge::plain($this->metrics, $this->symbolInfos, $other->metrics, $other->symbolInfos);
        $merged->metrics = $plain['metrics'];
        $merged->symbolInfos = $plain['infos'];
        $merged->subjectIndex = $this->subjectIndex->mergeWith($other->subjectIndex);
        $merged->subjectIndex->synchronizeAggregateInfos($merged->symbolInfos);
        $merged->namespaceIndex->rebuild($merged->symbolInfos, $merged->subjectIndex->infos());

        return $merged;
    }

    private function addLogicalClassProjection(SymbolPath $symbol, MetricBag $metrics): void
    {
        $info = $this->subjectIndex->addLogicalClass($symbol, $metrics, null, null);
        $this->namespaceIndex->add($info);
    }

    /**
     * @param array<string, true> $seen
     *
     * @return iterable<SymbolInfo>
     */
    private function unseenLogicalClasses(array $seen): iterable
    {
        foreach ($this->allLogicalClasses() as $info) {
            if (!isset($seen[$info->symbolPath->toCanonical()])) {
                yield $info;
            }
        }
    }

    /** @return iterable<SymbolInfo> */
    private function callablesOfType(SymbolType $type): iterable
    {
        foreach ($this->allCallables() as $info) {
            if ($info->symbolPath->getType() === $type) {
                yield $info;
            }
        }
    }

}
