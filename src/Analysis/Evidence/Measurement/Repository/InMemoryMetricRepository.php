<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Measurement\Repository;

use InvalidArgumentException;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\CallableWithMetrics;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricBag;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricRepositoryInterface;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\LogicalClassPath;
use Qualimetrix\Core\Symbol\MetricSubject;
use Qualimetrix\Core\Symbol\MixedSpelling;
use Qualimetrix\Core\Symbol\SymbolInfo;
use Qualimetrix\Core\Symbol\SymbolLevel;
use Qualimetrix\Core\Symbol\SymbolLevelProjection;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Core\Symbol\SymbolType;

/**
 * Coordinates exact, logical and aggregate metric identities with namespace attribution.
 *
 * @qmx-threshold complexity.wmc warning=63 -- The 17-operation repository port and its shared identity routes have WMC 62 after removing duplicate reads, writes and merge entrypoints.
 *                Moving cross-index routing into an identity index makes that index own the others; one-point headroom keeps further branching visible.
 * @qmx-threshold size.method-count warning=21 error=21 -- The required port and shared rekey routes contribute 20 counted methods after removing three redundant entrypoints.
 *                Inlining shared routes duplicates identity coordination; the next counted method reaches this inclusive boundary.
 */
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

    public function mergedWith(MetricRepositoryInterface $other): ?self
    {
        if (!$other instanceof self) {
            return null;
        }

        $namespaceSpellings = new NamespaceMetricIndex();
        $namespaceSpellings->importSpellings($this->namespaceIndex);
        $namespaceSpellings->importSpellings($other->namespaceIndex);
        $merged = new self();
        $merged->aggregateIndex = $this->aggregateIndex->mergeWith($other->aggregateIndex, $namespaceSpellings);
        $merged->subjectIndex = $this->subjectIndex->mergeWith($other->subjectIndex);
        $merged->logicalClassIndex = $this->logicalClassIndex->mergeWith($other->logicalClassIndex);
        $merged->namespaceIndex->rebuild(
            $merged->aggregateIndex->infos(),
            [...$merged->subjectIndex->infos(), ...$merged->logicalClassIndex->infos()],
        );
        $merged->namespaceIndex->importSpellings($namespaceSpellings);

        return $merged;
    }

    public function get(SymbolPath $symbol): MetricBag
    {
        return $this->metricsOfSymbol($symbol) ?? new MetricBag();
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
        return $this->metricsOfSymbol($symbol) !== null;
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
        $this->storeAggregate($symbol, $metrics, $file, $line);
    }

    public function getSubject(MetricSubject $subject): MetricBag
    {
        return $this->metricsOfSubject($subject) ?? new MetricBag();
    }

    public function hasSubject(MetricSubject $subject): bool
    {
        return $this->metricsOfSubject($subject) !== null;
    }

    public function addSubject(MetricSubject $subject, MetricBag $metrics, ?RelativePath $file, ?int $line): void
    {
        $line = $line === 0 ? null : $line;
        $aggregate = $subject->aggregatePath();
        if ($aggregate !== null) {
            $this->storeAggregate($aggregate, $metrics, $file, $line);

            return;
        }
        $logicalClass = $subject->logicalClassPath();
        if ($logicalClass !== null) {
            $this->addLogicalClass($logicalClass->symbolPath, $metrics, $file, $line);

            return;
        }

        $info = $this->subjectIndex->add($subject, $metrics, $file, $line);
        $this->indexExactSubject($info, $metrics);
    }

    public function addCallable(CallableWithMetrics $callable): void
    {
        $info = $this->subjectIndex->addCallable($callable);
        $this->indexExactSubject($info, $callable->metrics);
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
        if ($symbol->getType() === SymbolType::Class_) {
            $this->addSubjectScalar(MetricSubject::logicalClass(new LogicalClassPath($symbol)), $key, $value);

            return;
        }
        if (\in_array($symbol->getType(), [SymbolType::Method, SymbolType::Function_], true)) {
            return;
        }

        $this->addSubjectScalar(MetricSubject::aggregate($symbol), $key, $value);
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

    public function addSubjectScalar(MetricSubject $subject, string $key, int|float $value): void
    {
        $aggregate = $subject->aggregatePath();
        if ($aggregate !== null) {
            $this->aggregateIndex->addScalar($this->canonicalNamespaceSymbol($aggregate), $key, $value);

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

        $metrics = (new MetricBag())->with($key, $value);
        $info = $this->subjectIndex->add($subject, $metrics, null, null);
        $this->indexExactSubject($info, $metrics);
    }

    private function metricsOfSymbol(SymbolPath $symbol): ?MetricBag
    {
        $symbol = $this->canonicalNamespaceSymbol($symbol);
        $aggregate = $this->aggregateIndex->get($symbol);
        if ($aggregate !== null) {
            return $aggregate;
        }

        return $symbol->getType() === SymbolType::Class_
            ? $this->logicalClassIndex->logicalClassMetrics($symbol)
            : $this->subjectIndex->logicalCallableMetrics($symbol->toCanonical());
    }

    private function metricsOfSubject(MetricSubject $subject): ?MetricBag
    {
        $aggregate = $subject->aggregatePath();
        if ($aggregate !== null) {
            return $this->metricsOfSymbol($aggregate);
        }
        if ($subject->logicalClassPath() !== null) {
            return $this->logicalClassIndex->has($subject) ? $this->logicalClassIndex->get($subject) : null;
        }

        return $this->subjectIndex->has($subject) ? $this->subjectIndex->get($subject) : null;
    }

    private function storeAggregate(SymbolPath $symbol, MetricBag $metrics, ?RelativePath $file, ?int $line): void
    {
        if ($symbol->getType() === SymbolType::Namespace_) {
            $symbol = SymbolPath::forNamespace($this->observeNamespace($symbol->namespace ?? ''));
        }

        $info = $this->aggregateIndex->add($symbol, $metrics, $file, $line);
        $this->namespaceIndex->add($info);
    }

    private function indexExactSubject(SymbolInfo $info, MetricBag $metrics): void
    {
        $projection = $this->logicalClassIndex->project($info, $metrics);
        if ($info->symbolPath->namespace !== null) {
            $this->observeNamespace($info->symbolPath->namespace);
        }

        $declaration = $info->subject?->declarationPath();
        if ($declaration?->logical->getType() !== SymbolType::Class_) {
            $this->namespaceIndex->add($info);
        }

        if ($projection !== null) {
            $this->namespaceIndex->add($projection);
        }
    }

    private function addLogicalClass(SymbolPath $symbol, MetricBag $metrics, ?RelativePath $file, ?int $line): void
    {
        $this->observeNamespace($symbol->namespace ?? '');
        $info = $this->logicalClassIndex->addLogicalClass($symbol, $metrics, $file, $line === 0 ? null : $line);
        $this->namespaceIndex->add($info);
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
