<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Repository;

use InvalidArgumentException;
use Qualimetrix\Core\Metric\CallableWithMetrics;
use Qualimetrix\Core\Metric\MetricBag;
use Qualimetrix\Core\Metric\MetricRepositoryInterface;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\LogicalClassPath;
use Qualimetrix\Core\Symbol\MetricSubject;
use Qualimetrix\Core\Symbol\SymbolInfo;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Core\Symbol\SymbolType;

final class InMemoryMetricRepository implements MetricRepositoryInterface
{
    /** @var array<string, MetricBag> canonical -> MetricBag */
    private array $metrics = [];

    /** @var array<string, SymbolInfo> canonical -> SymbolInfo */
    private array $symbolInfos = [];

    /** @var array<string, list<SymbolInfo>> namespace -> list of SymbolInfo */
    private array $byNamespace = [];

    /** @var array<string, true> set of unique namespaces */
    private array $namespaceSet = [];

    /** @var array<string, MetricBag> typed canonical subject -> MetricBag */
    private array $subjectMetrics = [];

    /** @var array<string, SymbolInfo> typed canonical subject -> SymbolInfo */
    private array $subjectInfos = [];

    /** @var array<string, list<string>> logical callable canonical -> declaration canonical keys */
    private array $declarationsByLogical = [];

    public function get(SymbolPath $symbol): MetricBag
    {
        $canonical = $symbol->toCanonical();

        if (isset($this->metrics[$canonical])) {
            return $this->metrics[$canonical];
        }

        if ($symbol->getType() === SymbolType::Class_) {
            return $this->getSubject(MetricSubject::logicalClass(new LogicalClassPath($symbol)));
        }

        if (\in_array($symbol->getType(), [SymbolType::Method, SymbolType::Function_], true)) {
            $declarations = $this->declarationsByLogical[$canonical] ?? [];
            if (\count($declarations) === 1) {
                return $this->subjectMetrics[$declarations[0]] ?? new MetricBag();
            }
        }

        return new MetricBag();
    }

    public function all(SymbolType $type): iterable
    {
        $seen = [];
        foreach ($this->symbolInfos as $info) {
            if ($info->symbolPath->getType() === $type) {
                $seen[$info->symbolPath->toCanonical()] = true;
                yield $info;
            }
        }

        if ($type === SymbolType::Class_) {
            foreach ($this->allLogicalClasses() as $info) {
                if (isset($seen[$info->symbolPath->toCanonical()])) {
                    continue;
                }
                $seen[$info->symbolPath->toCanonical()] = true;
                yield $info;
            }
        }

        if (\in_array($type, [SymbolType::Method, SymbolType::Function_], true)) {
            foreach ($this->allCallables() as $info) {
                if ($info->symbolPath->getType() === $type) {
                    yield $info;
                }
            }
        }
    }

    public function has(SymbolPath $symbol): bool
    {
        $canonical = $symbol->toCanonical();

        if (isset($this->metrics[$canonical])) {
            return true;
        }

        if ($symbol->getType() === SymbolType::Class_) {
            return $this->hasSubject(MetricSubject::logicalClass(new LogicalClassPath($symbol)));
        }

        return \in_array($symbol->getType(), [SymbolType::Method, SymbolType::Function_], true)
            && \count($this->declarationsByLogical[$canonical] ?? []) === 1;
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
            $this->addSubject(MetricSubject::logicalClass(new LogicalClassPath($symbol)), $metrics, $file, $line);

            return;
        }

        $canonical = $symbol->toCanonical();

        if (isset($this->metrics[$canonical])) {
            // Merge with existing metrics
            $this->metrics[$canonical] = $this->metrics[$canonical]->merge($metrics);

            // Update line if existing SymbolInfo has line=0 and new line is positive
            if ($line !== null && $line > 0 && $this->symbolInfos[$canonical]->line === 0) {
                $oldInfo = $this->symbolInfos[$canonical];
                $this->symbolInfos[$canonical] = new SymbolInfo($oldInfo->symbolPath, $oldInfo->file, $line);
            }
        } else {
            $this->metrics[$canonical] = $metrics;
            $info = new SymbolInfo($symbol, $file, $line);
            $this->symbolInfos[$canonical] = $info;

            // Update namespace index (null = file-level, skip indexing)
            $namespace = $symbol->namespace;
            if ($namespace !== null && $symbol->getType() !== SymbolType::Project) {
                $this->byNamespace[$namespace][] = $info;
                $this->namespaceSet[$namespace] = true;
            }
        }
    }

    public function getSubject(MetricSubject $subject): MetricBag
    {
        return $this->subjectMetrics[$subject->toCanonical()] ?? new MetricBag();
    }

    public function hasSubject(MetricSubject $subject): bool
    {
        return isset($this->subjectMetrics[$subject->toCanonical()]);
    }

    public function addSubject(MetricSubject $subject, MetricBag $metrics, ?RelativePath $file, ?int $line): void
    {
        // Keep an unknown location as null; Location explicitly rejects
        // synthetic 0.
        $line = $line === 0 ? null : $line;
        $canonical = $subject->toCanonical();

        if (isset($this->subjectMetrics[$canonical])) {
            $this->subjectMetrics[$canonical] = $this->subjectMetrics[$canonical]->merge($metrics);

            $existing = $this->subjectInfos[$canonical];
            if ($line !== null && $line > 0 && ($existing->line === null || $existing->line === 0)) {
                $this->subjectInfos[$canonical] = new SymbolInfo(
                    $existing->subject ?? $existing->symbolPath,
                    $existing->file,
                    $line,
                    $existing->callableKind,
                    $existing->classAggregationOwner,
                );
            }

        } else {
            $this->subjectMetrics[$canonical] = $metrics;
            $this->subjectInfos[$canonical] = new SymbolInfo($subject, $file, $line);
        }

        $declaration = $subject->declarationPath();
        if ($declaration?->logical->getType() === SymbolType::Class_) {
            // Exact class facts remain addressable by DeclarationPath. Their
            // logical projection is the separate class-facing view used by
            // aggregation, graph construction, and legacy SymbolPath reads.
            // Do not index the declaration itself: it would count alongside
            // its projection during namespace aggregation.
            $this->addLogicalClassProjection(new LogicalClassPath($declaration->logical), $metrics);

            return;
        }

        $this->indexNamespaceInfo($this->subjectInfos[$canonical]);
    }

    public function addCallable(CallableWithMetrics $callable): void
    {
        $subject = MetricSubject::declaration($callable->declarationPath);
        $canonical = $subject->toCanonical();
        $info = new SymbolInfo(
            $subject,
            $callable->declarationPath->file,
            $callable->sourceLine,
            $callable->kind,
            $callable->classAggregationOwner,
        );

        if (isset($this->subjectMetrics[$canonical])) {
            $this->subjectMetrics[$canonical] = $this->subjectMetrics[$canonical]->merge($callable->metrics);
            $existing = $this->subjectInfos[$canonical];
            $this->subjectInfos[$canonical] = self::mergeSubjectInfo($existing, $info);
            $this->replaceNamespaceInfo($existing, $this->subjectInfos[$canonical]);
        } else {
            $this->subjectMetrics[$canonical] = $callable->metrics;
            $this->subjectInfos[$canonical] = $info;
        }

        $logicalCanonical = $callable->declarationPath->logical->toCanonical();
        $this->declarationsByLogical[$logicalCanonical] ??= [];
        if (!\in_array($canonical, $this->declarationsByLogical[$logicalCanonical], true)) {
            $this->declarationsByLogical[$logicalCanonical][] = $canonical;
        }

        $namespace = $callable->declarationPath->logical->namespace;
        if ($namespace !== null) {
            $this->indexNamespaceInfo($this->subjectInfos[$canonical]);
        }

        if ($callable->classAggregationOwner !== null) {
            $this->addLogicalClassProjection($callable->classAggregationOwner, new MetricBag());
        }
    }

    public function allDeclarations(): iterable
    {
        foreach ($this->subjectInfos as $info) {
            if ($info->subject?->declarationPath() !== null) {
                yield $info;
            }
        }
    }

    public function allCallables(): iterable
    {
        foreach ($this->subjectInfos as $info) {
            if ($info->callableKind !== null) {
                yield $info;
            }
        }
    }

    public function allLogicalClasses(): iterable
    {
        foreach ($this->subjectInfos as $info) {
            if ($info->subject?->logicalClassPath() !== null) {
                yield $info;
            }
        }
    }

    public function addScalar(SymbolPath $symbol, string $key, int|float $value): void
    {
        $canonical = $symbol->toCanonical();

        if ($symbol->getType() === SymbolType::Class_) {
            $subject = MetricSubject::logicalClass(new LogicalClassPath($symbol));
            $subjectCanonical = $subject->toCanonical();
            if (isset($this->subjectMetrics[$subjectCanonical])) {
                $this->subjectMetrics[$subjectCanonical] = $this->subjectMetrics[$subjectCanonical]->with($key, $value);
            }

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
        $namespaces = array_keys($this->namespaceSet);
        sort($namespaces);

        return $namespaces;
    }

    /**
     * Returns all metrics for symbols in a given namespace.
     *
     * @return list<SymbolInfo>
     */
    public function forNamespace(string $namespace): array
    {
        return $this->byNamespace[$namespace] ?? [];
    }

    /**
     * Creates a new repository with metrics merged from both repositories.
     *
     * If both repositories have metrics for the same symbol, they are merged.
     */
    public function mergeWith(self $other): self
    {
        $merged = new self();

        // Copy all from this repository
        foreach ($this->symbolInfos as $canonical => $info) {
            $merged->metrics[$canonical] = $this->metrics[$canonical];
            $merged->symbolInfos[$canonical] = $info;
        }

        // Copy namespace indexes from this repository
        foreach ($this->byNamespace as $namespace => $infos) {
            $merged->byNamespace[$namespace] = $infos;
        }
        $merged->namespaceSet = $this->namespaceSet;

        // Merge from other repository
        foreach ($other->symbolInfos as $canonical => $info) {
            if (isset($merged->metrics[$canonical])) {
                // Merge metrics for same symbol
                $merged->metrics[$canonical] = $merged->metrics[$canonical]->merge($other->metrics[$canonical]);

                // Update line if existing SymbolInfo has line=0 and other has positive line
                if ($info->line !== null && $info->line > 0 && $merged->symbolInfos[$canonical]->line === 0) {
                    $merged->symbolInfos[$canonical] = new SymbolInfo(
                        $merged->symbolInfos[$canonical]->symbolPath,
                        $merged->symbolInfos[$canonical]->file,
                        $info->line,
                    );
                }
            } else {
                $merged->metrics[$canonical] = $other->metrics[$canonical];
                $merged->symbolInfos[$canonical] = $info;

                // Update namespace index for new symbols (skip project-level)
                $namespace = $info->symbolPath->namespace;
                if ($namespace !== null && $info->symbolPath->getType() !== SymbolType::Project) {
                    $merged->byNamespace[$namespace][] = $info;
                    $merged->namespaceSet[$namespace] = true;
                }
            }
        }

        foreach ($this->subjectInfos as $canonical => $info) {
            $merged->subjectInfos[$canonical] = $info;
            $merged->subjectMetrics[$canonical] = $this->subjectMetrics[$canonical];
        }

        foreach ($other->subjectInfos as $canonical => $info) {
            if (isset($merged->subjectMetrics[$canonical])) {
                $merged->subjectMetrics[$canonical] = $merged->subjectMetrics[$canonical]->merge(
                    $other->subjectMetrics[$canonical],
                );
                $merged->subjectInfos[$canonical] = self::mergeSubjectInfo(
                    $merged->subjectInfos[$canonical],
                    $info,
                );
            } else {
                $merged->subjectInfos[$canonical] = $info;
                $merged->subjectMetrics[$canonical] = $other->subjectMetrics[$canonical];
            }
        }

        $merged->rebuildTypedIndexes();

        return $merged;
    }

    private function rebuildTypedIndexes(): void
    {
        $this->declarationsByLogical = [];
        $this->byNamespace = [];
        $this->namespaceSet = [];

        foreach ($this->symbolInfos as $info) {
            $this->indexNamespaceInfo($info);
        }

        foreach ($this->subjectInfos as $canonical => $info) {
            $declaration = $info->subject?->declarationPath();
            if ($declaration !== null && $info->callableKind !== null) {
                $this->declarationsByLogical[$declaration->logical->toCanonical()][] = $canonical;
            }

            if ($declaration?->logical->getType() !== SymbolType::Class_) {
                $this->indexNamespaceInfo($info);
            }
        }
    }

    private function indexNamespaceInfo(SymbolInfo $info): void
    {
        $symbol = $info->symbolPath;
        $namespace = $symbol->namespace;
        if ($namespace === null || $symbol->getType() === SymbolType::Project) {
            return;
        }

        $canonical = $info->subject?->toCanonical() ?? $symbol->toCanonical();
        foreach ($this->byNamespace[$namespace] ?? [] as $existing) {
            $existingCanonical = $existing->subject?->toCanonical() ?? $existing->symbolPath->toCanonical();
            if ($existingCanonical === $canonical) {
                return;
            }
        }

        $this->byNamespace[$namespace][] = $info;
        $this->namespaceSet[$namespace] = true;
    }

    private function replaceNamespaceInfo(SymbolInfo $old, SymbolInfo $new): void
    {
        $canonical = $old->subject?->toCanonical() ?? $old->symbolPath->toCanonical();
        foreach ($this->byNamespace as &$infos) {
            foreach ($infos as $index => $existing) {
                $existingCanonical = $existing->subject?->toCanonical() ?? $existing->symbolPath->toCanonical();
                if ($existingCanonical === $canonical) {
                    $infos[$index] = $new;

                    return;
                }
            }
        }
        unset($infos);
    }

    private static function mergeSubjectInfo(SymbolInfo $left, SymbolInfo $right): SymbolInfo
    {
        $leftTyped = $left->callableKind !== null;
        $rightTyped = $right->callableKind !== null;

        if (!$leftTyped && !$rightTyped) {
            $line = $left->line;
            if (($line === null || $line === 0) && $right->line !== null && $right->line > 0) {
                $line = $right->line;
            }

            return new SymbolInfo(
                $left->subject ?? $left->symbolPath,
                $left->file ?? $right->file,
                $line,
            );
        }

        if (!$leftTyped) {
            return $right;
        }

        if (!$rightTyped) {
            return $left;
        }

        if ($left->callableKind !== $right->callableKind
            || !self::sameLogicalClass($left->classAggregationOwner, $right->classAggregationOwner)
            || !self::sameFile($left->file, $right->file)
            || !self::sameSourceLine($left->line, $right->line)
        ) {
            throw new InvalidArgumentException(\sprintf(
                'Conflicting callable metadata for %s',
                $left->subject?->toCanonical() ?? $left->symbolPath->toCanonical(),
            ));
        }

        return new SymbolInfo(
            $left->subject ?? $left->symbolPath,
            $left->file,
            $left->line ?? $right->line,
            $left->callableKind,
            $left->classAggregationOwner,
        );
    }

    private static function sameLogicalClass(?LogicalClassPath $left, ?LogicalClassPath $right): bool
    {
        return $left?->toCanonical() === $right?->toCanonical();
    }

    private static function sameFile(?RelativePath $left, ?RelativePath $right): bool
    {
        return $left?->value() === $right?->value();
    }

    private static function sameSourceLine(?int $left, ?int $right): bool
    {
        return $left === null || $right === null || $left === $right;
    }

    private function addLogicalClassProjection(LogicalClassPath $path, MetricBag $metrics): void
    {
        $this->addSubject(MetricSubject::logicalClass($path), $metrics, null, null);
    }
}
