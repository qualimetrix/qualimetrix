<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Measurement\Repository;

use LogicException;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\CallableWithMetrics;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricBag;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\ClassNameSpelling;
use Qualimetrix\Core\Symbol\LogicalClassPath;
use Qualimetrix\Core\Symbol\MetricSubject;
use Qualimetrix\Core\Symbol\MixedSpelling;
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

    /** @var array<string, array<string, true>> */
    private array $logicalClassSpellings = [];

    /** @var array<string, string> */
    private array $logicalClassCanonicalByFold = [];

    public function get(MetricSubject $subject): MetricBag
    {
        $logicalClass = $subject->logicalClassPath();
        if ($logicalClass !== null) {
            return $this->logicalClassMetrics($logicalClass->symbolPath) ?? new MetricBag();
        }

        return $this->metrics[$subject->toCanonical()] ?? new MetricBag();
    }

    public function has(MetricSubject $subject): bool
    {
        $logicalClass = $subject->logicalClassPath();
        if ($logicalClass !== null) {
            return $this->logicalClassMetrics($logicalClass->symbolPath) !== null;
        }

        return isset($this->metrics[$subject->toCanonical()]);
    }

    public function logicalClassMetrics(SymbolPath $symbol): ?MetricBag
    {
        $folded = ClassNameSpelling::fold($symbol->toString());
        $canonical = $this->logicalClassCanonicalByFold[$folded] ?? null;

        return $canonical !== null ? ($this->metrics[$canonical] ?? null) : null;
    }

    public function addLogicalClass(SymbolPath $symbol, MetricBag $metrics, ?RelativePath $file, ?int $line): SymbolInfo
    {
        return $this->storeLogicalClass(new SymbolInfo($this->logicalClassSubject($symbol), $file, $line), $metrics);
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
        $info = new SymbolInfo($subject, $file, $line);

        return $subject->logicalClassPath() !== null
            ? $this->storeLogicalClass($info, $metrics)
            : $this->store($info, $metrics);
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
        if ($info->subject?->logicalClassPath() !== null) {
            return $this->storeLogicalClass($info, $metrics);
        }

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

    /** @return list<MixedSpelling> */
    public function mixedSpellings(): array
    {
        $mixed = [];
        foreach ($this->logicalClassSpellings as $spellings) {
            $names = array_keys($spellings);
            sort($names, \SORT_STRING);
            if (\count($names) > 1) {
                $mixed[] = new MixedSpelling('class', $names, $names[0]);
            }
        }
        usort($mixed, static fn(MixedSpelling $left, MixedSpelling $right): int => $left->canonical <=> $right->canonical);

        return $mixed;
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

    private function storeLogicalClass(SymbolInfo $info, MetricBag $metrics): SymbolInfo
    {
        $subject = $info->subject;
        $logical = $subject?->logicalClassPath();
        if ($logical === null) {
            throw new LogicException('Logical class storage requires a logical-class subject');
        }

        $spelling = $logical->symbolPath->toString();
        [$canonicalSubject, $canonical] = $this->observeLogicalClassSpelling($spelling);

        $canonicalInfo = new SymbolInfo(
            $canonicalSubject,
            $info->file,
            $info->line,
            $info->callableKind,
            $info->classAggregationOwner,
        );
        if (isset($this->metrics[$canonical])) {
            $this->metrics[$canonical] = RepositoryMerge::metrics($this->metrics[$canonical], $metrics);
            $this->infos[$canonical] = RepositoryMerge::subjectInfo($this->infos[$canonical], $canonicalInfo);
        } else {
            $this->metrics[$canonical] = $metrics;
            $this->infos[$canonical] = $canonicalInfo;
        }
        return $this->infos[$canonical];
    }

    private function copyTo(self $target): void
    {
        foreach ($this->logicalClassSpellings as $spellings) {
            foreach (array_keys($spellings) as $spelling) {
                $target->observeLogicalClassSpelling($spelling);
            }
        }
        foreach ($this->infos as $canonical => $info) {
            $target->import($info, $this->metrics[$canonical]);
        }
    }

    /** @return array{MetricSubject, string} */
    private function observeLogicalClassSpelling(string $spelling): array
    {
        $folded = ClassNameSpelling::fold($spelling);
        $this->logicalClassSpellings[$folded][$spelling] = true;
        $canonicalSpelling = ClassNameSpelling::canonical(array_keys($this->logicalClassSpellings[$folded]));
        $canonicalSubject = $this->logicalClassSubject(SymbolPath::fromClassFqn($canonicalSpelling));
        $canonical = $canonicalSubject->toCanonical();
        $previousCanonical = $this->logicalClassCanonicalByFold[$folded] ?? null;

        if ($previousCanonical !== null && $previousCanonical !== $canonical && isset($this->metrics[$previousCanonical])) {
            $previousMetrics = $this->metrics[$previousCanonical];
            $previousInfo = $this->infos[$previousCanonical];
            unset($this->metrics[$previousCanonical], $this->infos[$previousCanonical]);
            $this->metrics[$canonical] = $previousMetrics;
            $this->infos[$canonical] = new SymbolInfo(
                $canonicalSubject,
                $previousInfo->file,
                $previousInfo->line,
                $previousInfo->callableKind,
                $previousInfo->classAggregationOwner,
            );
        }
        $this->logicalClassCanonicalByFold[$folded] = $canonical;

        return [$canonicalSubject, $canonical];
    }

    private function logicalClassSubject(SymbolPath $symbol): MetricSubject
    {
        return MetricSubject::logicalClass(new LogicalClassPath($symbol));
    }

}
