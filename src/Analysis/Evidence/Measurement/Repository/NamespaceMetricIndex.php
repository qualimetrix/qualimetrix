<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Measurement\Repository;

use Qualimetrix\Core\Symbol\ClassNameSpelling;
use Qualimetrix\Core\Symbol\MixedSpelling;
use Qualimetrix\Core\Symbol\SymbolInfo;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Core\Symbol\SymbolType;

/**
 * Namespace projection of aggregate and typed repository subjects.
 */
final class NamespaceMetricIndex
{
    /** @var array<string, array<string, SymbolInfo>> */
    private array $infosByNamespace = [];

    /** @var array<string, array<string, true>> */
    private array $spellingsByNamespace = [];

    public function add(SymbolInfo $info): void
    {
        if ($info->subject?->aggregatePath() !== null) {
            return;
        }

        $symbol = $info->symbolPath;
        $namespace = $symbol->namespace;
        if ($namespace === null || $symbol->getType() === SymbolType::Project) {
            return;
        }

        $this->observe($namespace);
        $folded = ClassNameSpelling::fold($namespace);
        $logicalClass = $info->subject?->logicalClassPath();
        $canonical = match (true) {
            $symbol->getType() === SymbolType::Namespace_ => SymbolPath::forNamespace($folded)->toCanonical(),
            $logicalClass !== null => 'logical-class-folded:' . ClassNameSpelling::fold($logicalClass->symbolPath->toString()),
            default => $info->subject?->toCanonical() ?? $symbol->toCanonical(),
        };
        if ($symbol->getType() === SymbolType::Namespace_) {
            $info = new SymbolInfo(SymbolPath::forNamespace($this->canonical($namespace)), $info->file, $info->line);
        }
        $this->infosByNamespace[$folded][$canonical] = $info;
    }

    public function observe(string $namespace): void
    {
        $folded = ClassNameSpelling::fold($namespace);
        $this->spellingsByNamespace[$folded][$namespace] = true;
        $key = SymbolPath::forNamespace($folded)->toCanonical();
        $info = $this->infosByNamespace[$folded][$key] ?? null;
        if ($info !== null) {
            $this->infosByNamespace[$folded][$key] = new SymbolInfo(
                SymbolPath::forNamespace($this->canonical($namespace)),
                $info->file,
                $info->line,
            );
        }
    }

    /**
     * @param iterable<SymbolInfo> $plainInfos
     * @param iterable<SymbolInfo> $subjectInfos
     */
    public function rebuild(iterable $plainInfos, iterable $subjectInfos): void
    {
        $this->infosByNamespace = [];
        $this->spellingsByNamespace = [];

        foreach ($plainInfos as $info) {
            $this->add($info);
        }

        foreach ($subjectInfos as $info) {
            $classDeclaration = $info->subject?->declarationPath();
            if ($classDeclaration?->logical->getType() === SymbolType::Class_) {
                $this->observe($classDeclaration->logical->namespace ?? '');
            } elseif ($info->subject?->aggregatePath() === null) {
                $this->add($info);
            }
        }
    }

    /** @return list<string> */
    public function namespaces(): array
    {
        $namespaces = array_map(
            static fn(array $spellings): string => ClassNameSpelling::canonical(array_keys($spellings)),
            $this->spellingsByNamespace,
        );
        sort($namespaces, \SORT_STRING);

        return $namespaces;
    }

    /** @return list<SymbolInfo> */
    public function forNamespace(string $namespace): array
    {
        return array_values($this->infosByNamespace[ClassNameSpelling::fold($namespace)] ?? []);
    }

    public function canonical(string $namespace): string
    {
        $spellings = $this->spellingsByNamespace[ClassNameSpelling::fold($namespace)] ?? null;

        return $spellings === null ? $namespace : ClassNameSpelling::canonical(array_keys($spellings));
    }

    public function importSpellings(self $source): void
    {
        foreach ($source->spellingsByNamespace as $spellings) {
            foreach (array_keys($spellings) as $spelling) {
                $this->observe($spelling);
            }
        }
    }

    /** @return list<MixedSpelling> */
    public function mixedSpellings(): array
    {
        $mixed = [];
        foreach ($this->spellingsByNamespace as $spellings) {
            $names = array_keys($spellings);
            sort($names, \SORT_STRING);
            if (\count($names) > 1) {
                $mixed[] = new MixedSpelling('namespace', $names, $names[0]);
            }
        }
        usort($mixed, static fn(MixedSpelling $left, MixedSpelling $right): int => $left->canonical <=> $right->canonical);

        return $mixed;
    }
}
