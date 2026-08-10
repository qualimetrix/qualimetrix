<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Repository;

use Qualimetrix\Core\Symbol\SymbolInfo;
use Qualimetrix\Core\Symbol\SymbolType;

/**
 * Namespace projection of aggregate and typed repository subjects.
 */
final class NamespaceMetricIndex
{
    /** @var array<string, array<string, SymbolInfo>> */
    private array $infosByNamespace = [];

    /** @var array<string, true> */
    private array $namespaceSet = [];

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

        $canonical = $info->subject?->toCanonical() ?? $symbol->toCanonical();
        $this->infosByNamespace[$namespace][$canonical] = $info;
        $this->namespaceSet[$namespace] = true;
    }

    /**
     * @param iterable<SymbolInfo> $plainInfos
     * @param iterable<SymbolInfo> $subjectInfos
     */
    public function rebuild(iterable $plainInfos, iterable $subjectInfos): void
    {
        $this->infosByNamespace = [];
        $this->namespaceSet = [];

        foreach ($plainInfos as $info) {
            $this->add($info);
        }

        foreach ($subjectInfos as $info) {
            if ($info->subject?->aggregatePath() === null
                && $info->subject?->declarationPath()?->logical->getType() !== SymbolType::Class_
            ) {
                $this->add($info);
            }
        }
    }

    /** @return list<string> */
    public function namespaces(): array
    {
        $namespaces = array_keys($this->namespaceSet);
        sort($namespaces);

        return $namespaces;
    }

    /** @return list<SymbolInfo> */
    public function forNamespace(string $namespace): array
    {
        return array_values($this->infosByNamespace[$namespace] ?? []);
    }
}
