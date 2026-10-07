<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Measurement\Repository;

use LogicException;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricBag;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\ClassNameSpelling;
use Qualimetrix\Core\Symbol\LogicalClassPath;
use Qualimetrix\Core\Symbol\MetricSubject;
use Qualimetrix\Core\Symbol\MixedSpelling;
use Qualimetrix\Core\Symbol\SymbolInfo;
use Qualimetrix\Core\Symbol\SymbolPath;

/** Folded logical-class metrics, canonical spellings, and spelling evidence. */
final class LogicalClassMetricIndex
{
    /** @var array<string, MetricBag> */
    private array $metrics = [];

    /** @var array<string, SymbolInfo> */
    private array $infos = [];

    /** @var array<string, array<string, true>> */
    private array $spellings = [];

    /** @var array<string, string> */
    private array $canonicalByFold = [];

    public function get(MetricSubject $subject): MetricBag
    {
        $logical = $subject->logicalClassPath();

        return $logical !== null ? $this->logicalClassMetrics($logical->symbolPath) ?? new MetricBag() : new MetricBag();
    }

    public function has(MetricSubject $subject): bool
    {
        $logical = $subject->logicalClassPath();

        return $logical !== null && $this->logicalClassMetrics($logical->symbolPath) !== null;
    }

    public function logicalClassMetrics(SymbolPath $symbol): ?MetricBag
    {
        $canonical = $this->canonicalByFold[ClassNameSpelling::fold($symbol->toString())] ?? null;

        return $canonical !== null ? ($this->metrics[$canonical] ?? null) : null;
    }

    public function addLogicalClass(SymbolPath $symbol, MetricBag $metrics, ?RelativePath $file, ?int $line): SymbolInfo
    {
        return $this->store(new SymbolInfo(self::subject($symbol), $file, $line), $metrics);
    }

    public function addSubject(MetricSubject $subject, MetricBag $metrics, ?RelativePath $file, ?int $line): SymbolInfo
    {
        if ($subject->logicalClassPath() === null) {
            throw new LogicException('Logical class storage requires a logical-class subject');
        }

        return $this->store(new SymbolInfo($subject, $file, $line), $metrics);
    }

    public function addLogicalClassScalar(SymbolPath $symbol, string $key, int|float $value): void
    {
        $subject = self::subject($symbol);
        if ($this->has($subject)) {
            $this->addSubject($subject, (new MetricBag())->with($key, $value), null, null);
        }
    }

    /** @return iterable<SymbolInfo> */
    public function allLogicalClasses(): iterable
    {
        yield from $this->infos;
    }

    /** @return array<string, SymbolInfo> */
    public function infos(): array
    {
        return $this->infos;
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
        foreach ($this->spellings as $spellings) {
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
        $logical = $info->subject?->logicalClassPath();
        if ($logical === null) {
            throw new LogicException('Logical class storage requires a logical-class subject');
        }

        [$canonicalSubject, $canonical] = $this->observeSpelling($logical->symbolPath->toString());
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
        foreach ($this->spellings as $spellings) {
            foreach (array_keys($spellings) as $spelling) {
                $target->observeSpelling($spelling);
            }
        }
        foreach ($this->infos as $canonical => $info) {
            $target->store($info, $this->metrics[$canonical]);
        }
    }

    /** @return array{MetricSubject, string} */
    private function observeSpelling(string $spelling): array
    {
        $folded = ClassNameSpelling::fold($spelling);
        $this->spellings[$folded][$spelling] = true;
        $canonicalSpelling = ClassNameSpelling::canonical(array_keys($this->spellings[$folded]));
        $canonicalSubject = self::subject(SymbolPath::fromClassFqn($canonicalSpelling));
        $canonical = $canonicalSubject->toCanonical();
        $previous = $this->canonicalByFold[$folded] ?? null;

        if ($previous !== null && $previous !== $canonical && isset($this->metrics[$previous])) {
            $previousMetrics = $this->metrics[$previous];
            $previousInfo = $this->infos[$previous];
            unset($this->metrics[$previous], $this->infos[$previous]);
            $this->metrics[$canonical] = $previousMetrics;
            $this->infos[$canonical] = new SymbolInfo(
                $canonicalSubject,
                $previousInfo->file,
                $previousInfo->line,
                $previousInfo->callableKind,
                $previousInfo->classAggregationOwner,
            );
        }
        $this->canonicalByFold[$folded] = $canonical;

        return [$canonicalSubject, $canonical];
    }

    private static function subject(SymbolPath $symbol): MetricSubject
    {
        return MetricSubject::logicalClass(new LogicalClassPath($symbol));
    }
}
