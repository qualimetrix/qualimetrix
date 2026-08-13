<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Measurement\Repository;

use LogicException;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\CallableWithMetrics;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricBag;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\LogicalClassPath;
use Qualimetrix\Core\Symbol\MetricSubject;
use Qualimetrix\Core\Symbol\SymbolInfo;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Core\Symbol\SymbolType;

/**
 * Exact-subject storage and logical callable lookup for one metric repository.
 */
final class MetricSubjectIndex
{
    /** @var array<string, MetricBag> */
    private array $metrics = [];

    /** @var array<string, SymbolInfo> */
    private array $infos = [];

    /** @var array<string, list<string>> */
    private array $declarationsByLogical = [];

    public function get(MetricSubject $subject): MetricBag
    {
        return $this->metrics[$subject->toCanonical()] ?? new MetricBag();
    }

    public function has(MetricSubject $subject): bool
    {
        return isset($this->metrics[$subject->toCanonical()]);
    }

    public function logicalClassMetrics(SymbolPath $symbol): ?MetricBag
    {
        return $this->metrics[$this->logicalClassSubject($symbol)->toCanonical()] ?? null;
    }

    public function addLogicalClass(SymbolPath $symbol, MetricBag $metrics, ?RelativePath $file, ?int $line): SymbolInfo
    {
        return $this->add($this->logicalClassSubject($symbol), $metrics, $file, $line);
    }

    public function addLogicalClassScalar(SymbolPath $symbol, string $key, int|float $value): void
    {
        $subject = $this->logicalClassSubject($symbol);
        if ($this->has($subject)) {
            $this->add($subject, (new MetricBag())->with($key, $value), null, null);
        }
    }

    public function add(MetricSubject $subject, MetricBag $metrics, ?RelativePath $file, ?int $line): SymbolInfo
    {
        return $this->store(new SymbolInfo($subject, $file, $line), $metrics);
    }

    public function addCallable(CallableWithMetrics $callable): SymbolInfo
    {
        $subject = MetricSubject::declaration($callable->declarationPath);

        return $this->store(new SymbolInfo(
            $subject,
            $callable->declarationPath->file,
            $callable->sourceLine,
            $callable->kind,
            $callable->classAggregationOwner,
        ), $callable->metrics);
    }

    public function import(SymbolInfo $info, MetricBag $metrics): SymbolInfo
    {
        return $this->store($info, $metrics);
    }

    public function synchronizeAggregateInfo(SymbolInfo $info): void
    {
        $symbol = $info->symbolPath;
        if (!\in_array($symbol->getType(), [SymbolType::File, SymbolType::Namespace_, SymbolType::Project], true)) {
            return;
        }

        $canonical = MetricSubject::aggregate($symbol)->toCanonical();
        if (isset($this->infos[$canonical])) {
            $this->infos[$canonical] = RepositoryMerge::subjectInfo($this->infos[$canonical], $info);
        }
    }

    /** @param iterable<SymbolInfo> $infos */
    public function synchronizeAggregateInfos(iterable $infos): void
    {
        foreach ($infos as $info) {
            $this->synchronizeAggregateInfo($info);
        }
    }

    /** @return array<string, SymbolInfo> */
    public function infos(): array
    {
        return $this->infos;
    }

    /** @return list<string> */
    public function declarationsForLogical(string $canonical): array
    {
        return $this->declarationsByLogical[$canonical] ?? [];
    }

    public function logicalCallableMetrics(string $canonical): ?MetricBag
    {
        $declarations = $this->declarationsForLogical($canonical);

        return \count($declarations) === 1 ? $this->metrics[$declarations[0]] : null;
    }

    /** @return iterable<SymbolInfo> */
    public function allDeclarations(): iterable
    {
        foreach ($this->infos as $info) {
            if ($info->subject?->declarationPath() !== null) {
                yield $info;
            }
        }
    }

    /** @return iterable<SymbolInfo> */
    public function allCallables(): iterable
    {
        foreach ($this->infos as $info) {
            if ($info->callableKind !== null) {
                yield $info;
            }
        }
    }

    /** @return iterable<SymbolInfo> */
    public function allLogicalClasses(): iterable
    {
        foreach ($this->infos as $info) {
            if ($info->subject?->logicalClassPath() !== null) {
                yield $info;
            }
        }
    }

    public function mergeWith(self $other): self
    {
        $merged = new self();
        $this->copyTo($merged);
        $other->copyTo($merged);

        return $merged;
    }

    private function store(SymbolInfo $info, MetricBag $metrics): SymbolInfo
    {
        $subject = $info->subject;
        if ($subject === null) {
            throw new LogicException('Metric subject index requires typed SymbolInfo');
        }

        $canonical = $subject->toCanonical();
        if (isset($this->metrics[$canonical])) {
            $this->metrics[$canonical] = RepositoryMerge::metrics($this->metrics[$canonical], $metrics);
            $this->infos[$canonical] = RepositoryMerge::subjectInfo($this->infos[$canonical], $info);
        } else {
            $this->metrics[$canonical] = $metrics;
            $this->infos[$canonical] = $info;
        }

        $stored = $this->infos[$canonical];
        $declaration = $stored->subject?->declarationPath();
        if ($declaration !== null && $stored->callableKind !== null) {
            $logicalCanonical = $declaration->logical->toCanonical();
            $this->declarationsByLogical[$logicalCanonical] ??= [];
            if (!\in_array($canonical, $this->declarationsByLogical[$logicalCanonical], true)) {
                $this->declarationsByLogical[$logicalCanonical][] = $canonical;
            }
        }

        return $stored;
    }

    private function copyTo(self $target): void
    {
        foreach ($this->infos as $canonical => $info) {
            $target->import($info, $this->metrics[$canonical]);
        }
    }

    private function logicalClassSubject(SymbolPath $symbol): MetricSubject
    {
        return MetricSubject::logicalClass(new LogicalClassPath($symbol));
    }

}
