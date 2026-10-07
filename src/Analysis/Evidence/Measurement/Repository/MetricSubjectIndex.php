<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Measurement\Repository;

use LogicException;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\CallableWithMetrics;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricBag;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\MetricSubject;
use Qualimetrix\Core\Symbol\SymbolInfo;

/** Exact declaration metrics and logical callable lookup. */
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

    public function add(MetricSubject $subject, MetricBag $metrics, ?RelativePath $file, ?int $line): SymbolInfo
    {
        return $this->store(new SymbolInfo($subject, $file, $line), $metrics);
    }

    public function addCallable(CallableWithMetrics $callable): SymbolInfo
    {
        return $this->store(new SymbolInfo(
            MetricSubject::declaration($callable->declarationPath),
            $callable->declarationPath->file,
            $callable->sourceLine,
            $callable->kind,
            $callable->classAggregationOwner,
        ), $callable->metrics);
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
            $logical = $declaration->logical->toCanonical();
            $this->declarationsByLogical[$logical] ??= [];
            if (!\in_array($canonical, $this->declarationsByLogical[$logical], true)) {
                $this->declarationsByLogical[$logical][] = $canonical;
            }
        }

        return $stored;
    }

    private function copyTo(self $target): void
    {
        foreach ($this->infos as $canonical => $info) {
            $target->store($info, $this->metrics[$canonical]);
        }
    }
}
