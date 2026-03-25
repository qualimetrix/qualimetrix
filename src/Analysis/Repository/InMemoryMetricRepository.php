<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Repository;

use Qualimetrix\Core\Metric\MetricBag;
use Qualimetrix\Core\Metric\MetricRepositoryInterface;
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

    public function get(SymbolPath $symbol): MetricBag
    {
        $canonical = $symbol->toCanonical();

        return $this->metrics[$canonical] ?? new MetricBag();
    }

    public function all(SymbolType $type): iterable
    {
        foreach ($this->symbolInfos as $info) {
            if ($info->symbolPath->getType() === $type) {
                yield $info;
            }
        }
    }

    public function has(SymbolPath $symbol): bool
    {
        $canonical = $symbol->toCanonical();

        return isset($this->metrics[$canonical]);
    }

    /**
     * Adds or merges metrics for a symbol.
     *
     * If the symbol already has metrics, new metrics are merged (new values override).
     */
    public function add(SymbolPath $symbol, MetricBag $metrics, string $file, ?int $line): void
    {
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

    public function addScalar(SymbolPath $symbol, string $key, int|float $value): void
    {
        $canonical = $symbol->toCanonical();

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

        return $merged;
    }
}
