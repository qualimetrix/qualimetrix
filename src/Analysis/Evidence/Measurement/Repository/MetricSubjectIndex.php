<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Measurement\Repository;

use LogicException;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\CallableWithMetrics;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricBag;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\MetricSubject;
use Qualimetrix\Core\Symbol\SymbolInfo;
use Qualimetrix\Core\Symbol\SymbolType;

/** Exact declaration metrics and callable metadata. */
final class MetricSubjectIndex
{
    /** @var array<string, MetricBag> */
    private array $metrics = [];

    /** @var array<string, SymbolInfo> */
    private array $infos = [];

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
            $callable->classAggregationOwnerDeclaration,
            $callable->anonymousClassContext,
        ), $callable->metrics);
    }

    /** @return array{info: SymbolInfo, metrics: MetricBag} */
    public function addScalarToExisting(MetricSubject $subject, string $key, int|float $value): array
    {
        if (!$this->has($subject)) {
            throw new LogicException('Scalar requires an existing exact declaration');
        }

        $metrics = (new MetricBag())->with($key, $value);

        return ['info' => $this->add($subject, $metrics, null, null), 'metrics' => $metrics];
    }

    /** @return array<string, SymbolInfo> */
    public function infos(): array
    {
        return $this->infos;
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
    public function allClassDeclarations(): iterable
    {
        foreach ($this->allDeclarations() as $info) {
            if ($info->symbolPath->getType() === SymbolType::Class_) {
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
        return RepositoryMerge::store($canonical, $info, $metrics, $this->metrics, $this->infos);
    }

    private function copyTo(self $target): void
    {
        foreach ($this->infos as $canonical => $info) {
            $target->store($info, $this->metrics[$canonical]);
        }
    }
}
