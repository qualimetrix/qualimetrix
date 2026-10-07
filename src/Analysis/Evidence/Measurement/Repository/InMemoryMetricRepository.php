<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Measurement\Repository;

use InvalidArgumentException;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\CallableWithMetrics;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricBag;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricRepositoryInterface;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\MetricSubject;
use Qualimetrix\Core\Symbol\MixedSpelling;
use Qualimetrix\Core\Symbol\SymbolInfo;
use Qualimetrix\Core\Symbol\SymbolLevel;
use Qualimetrix\Core\Symbol\SymbolLevelProjection;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Core\Symbol\SymbolType;

final class InMemoryMetricRepository implements MetricRepositoryInterface
{
    private AggregateMetricIndex $aggregateIndex;
    private MetricSubjectIndex $subjectIndex;
    private LogicalClassMetricIndex $logicalClassIndex;
    private NamespaceMetricIndex $namespaceIndex;

    public function __construct()
    {
        $this->aggregateIndex = new AggregateMetricIndex();
        $this->subjectIndex = new MetricSubjectIndex();
        $this->logicalClassIndex = new LogicalClassMetricIndex();
        $this->namespaceIndex = new NamespaceMetricIndex();
    }

    public function mergedWith(MetricRepositoryInterface $other): ?MetricRepositoryInterface
    {
        return $other instanceof self ? $this->mergeWith($other) : null;
    }

    public function get(SymbolPath $symbol): MetricBag
    {
        $symbol = $this->canonicalNamespaceSymbol($symbol);
        $aggregate = $this->aggregateIndex->get($symbol);
        if ($aggregate !== null) {
            return $aggregate;
        }

        return $symbol->getType() === SymbolType::Class_
            ? $this->logicalClassIndex->logicalClassMetrics($symbol) ?? new MetricBag()
            : $this->subjectIndex->logicalCallableMetrics($symbol->toCanonical()) ?? new MetricBag();
    }

    public function all(SymbolLevel $level): iterable
    {
        if ($level === SymbolLevel::Callable) {
            yield from $this->allCallables();

            return;
        }
        if ($level === SymbolLevel::Class_) {
            yield from $this->allLogicalClasses();

            return;
        }

        foreach ($this->aggregateIndex->infos() as $info) {
            if (SymbolLevelProjection::ofDeclaration($info->symbolPath->getType()) === $level) {
                yield $info;
            }
        }
    }

    public function has(SymbolPath $symbol): bool
    {
        $symbol = $this->canonicalNamespaceSymbol($symbol);
        if ($this->aggregateIndex->has($symbol)) {
            return true;
        }

        return $symbol->getType() === SymbolType::Class_
            ? $this->logicalClassIndex->logicalClassMetrics($symbol) !== null
            : $this->subjectIndex->logicalCallableMetrics($symbol->toCanonical()) !== null;
    }

    public function add(SymbolPath $symbol, MetricBag $metrics, ?RelativePath $file, ?int $line): void
    {
        if (\in_array($symbol->getType(), [SymbolType::Method, SymbolType::Function_], true)) {
            throw new InvalidArgumentException('MetricRepositoryInterface::add() accepts aggregate or logical-class SymbolPath only; use addCallable() or addSubject() for declarations');
        }
        if ($symbol->getType() === SymbolType::Class_) {
            $this->addLogicalClass($symbol, $metrics, $file, $line);

            return;
        }
        if ($symbol->getType() === SymbolType::Namespace_) {
            $symbol = SymbolPath::forNamespace($this->observeNamespace($symbol->namespace ?? ''));
        }

        $info = $this->aggregateIndex->add($symbol, $metrics, $file, $line);
        $this->namespaceIndex->add($info);
    }

    public function getSubject(MetricSubject $subject): MetricBag
    {
        if ($subject->aggregatePath() !== null) {
            return $this->get($subject->aggregatePath());
        }
        if ($subject->logicalClassPath() !== null) {
            return $this->logicalClassIndex->get($subject);
        }

        return $this->subjectIndex->get($subject);
    }

    public function hasSubject(MetricSubject $subject): bool
    {
        if ($subject->aggregatePath() !== null) {
            return $this->has($subject->aggregatePath());
        }
        if ($subject->logicalClassPath() !== null) {
            return $this->logicalClassIndex->has($subject);
        }

        return $this->subjectIndex->has($subject);
    }

    public function addSubject(MetricSubject $subject, MetricBag $metrics, ?RelativePath $file, ?int $line): void
    {
        $line = $line === 0 ? null : $line;
        $aggregate = $subject->aggregatePath();
        if ($aggregate !== null) {
            $this->add($aggregate, $metrics, $file, $line);

            return;
        }
        if ($subject->logicalClassPath() !== null) {
            $info = $this->logicalClassIndex->addSubject($subject, $metrics, $file, $line);
            $this->namespaceIndex->add($info);

            return;
        }

        $info = $this->subjectIndex->add($subject, $metrics, $file, $line);
        $declaration = $subject->declarationPath();
        if ($declaration?->logical->getType() === SymbolType::Class_) {
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
        yield from $this->logicalClassIndex->allLogicalClasses();
    }

    public function addScalar(SymbolPath $symbol, string $key, int|float $value): void
    {
        $symbol = $this->canonicalNamespaceSymbol($symbol);
        if ($symbol->getType() === SymbolType::Class_) {
            $this->logicalClassIndex->addLogicalClassScalar($symbol, $key, $value);

            return;
        }

        $this->aggregateIndex->addScalar($symbol, $key, $value);
    }

    /** @return list<string> */
    public function getNamespaces(): array
    {
        return $this->namespaceIndex->namespaces();
    }

    /** @return list<SymbolInfo> */
    public function forNamespace(string $namespace): array
    {
        return $this->namespaceIndex->forNamespace($namespace);
    }

    /** @return list<MixedSpelling> */
    public function mixedSpellings(): array
    {
        return [...$this->logicalClassIndex->mixedSpellings(), ...$this->namespaceIndex->mixedSpellings()];
    }

    public function mergeWith(self $other): self
    {
        $merged = new self();
        $merged->aggregateIndex = $this->aggregateIndex->mergeWith($other->aggregateIndex);
        $merged->subjectIndex = $this->subjectIndex->mergeWith($other->subjectIndex);
        $merged->logicalClassIndex = $this->logicalClassIndex->mergeWith($other->logicalClassIndex);
        $merged->namespaceIndex->rebuild(
            $merged->aggregateIndex->infos(),
            [...$merged->subjectIndex->infos(), ...$merged->logicalClassIndex->infos()],
        );
        $merged->namespaceIndex->importSpellings($this->namespaceIndex);
        $merged->namespaceIndex->importSpellings($other->namespaceIndex);

        return $merged;
    }

    public function addSubjectScalar(MetricSubject $subject, string $key, int|float $value): void
    {
        $aggregate = $subject->aggregatePath();
        if ($aggregate !== null) {
            $this->addScalar($aggregate, $key, $value);

            return;
        }
        if ($subject->logicalClassPath() !== null) {
            if ($this->logicalClassIndex->has($subject)) {
                $this->logicalClassIndex->addSubject($subject, (new MetricBag())->with($key, $value), null, null);
            }

            return;
        }
        if (!$this->subjectIndex->has($subject)) {
            return;
        }

        $this->subjectIndex->add($subject, (new MetricBag())->with($key, $value), null, null);
        $declaration = $subject->declarationPath();
        if ($declaration?->logical->getType() === SymbolType::Class_) {
            $this->addLogicalClassProjection($declaration->logical, (new MetricBag())->with($key, $value));
        }
    }

    private function addLogicalClass(SymbolPath $symbol, MetricBag $metrics, ?RelativePath $file, ?int $line): void
    {
        $this->observeNamespace($symbol->namespace ?? '');
        $info = $this->logicalClassIndex->addLogicalClass($symbol, $metrics, $file, $line === 0 ? null : $line);
        $this->namespaceIndex->add($info);
    }

    private function addLogicalClassProjection(SymbolPath $symbol, MetricBag $metrics): void
    {
        $this->addLogicalClass($symbol, $metrics, null, null);
    }

    private function observeNamespace(string $namespace): string
    {
        $previous = $this->namespaceIndex->canonical($namespace);
        $this->namespaceIndex->observe($namespace);
        $canonical = $this->namespaceIndex->canonical($namespace);
        if ($previous !== $canonical) {
            $this->aggregateIndex->moveNamespace($previous, $canonical);
        }

        return $canonical;
    }

    private function canonicalNamespaceSymbol(SymbolPath $symbol): SymbolPath
    {
        return $symbol->getType() === SymbolType::Namespace_
            ? SymbolPath::forNamespace($this->namespaceIndex->canonical($symbol->namespace ?? ''))
            : $symbol;
    }
}
