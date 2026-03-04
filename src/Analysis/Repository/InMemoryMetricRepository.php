<?php

declare(strict_types=1);

namespace AiMessDetector\Analysis\Repository;

use AiMessDetector\Core\Metric\MetricBag;
use AiMessDetector\Core\Metric\MetricRepositoryInterface;
use AiMessDetector\Core\Symbol\SymbolInfo;
use AiMessDetector\Core\Symbol\SymbolType;
use AiMessDetector\Core\Violation\SymbolPath;

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
    public function add(SymbolPath $symbol, MetricBag $metrics, string $file, int $line): void
    {
        $canonical = $symbol->toCanonical();

        if (isset($this->metrics[$canonical])) {
            // Merge with existing metrics
            $this->metrics[$canonical] = $this->metrics[$canonical]->merge($metrics);
        } else {
            $this->metrics[$canonical] = $metrics;
            $info = new SymbolInfo($symbol, $file, $line);
            $this->symbolInfos[$canonical] = $info;

            // Update namespace index
            $namespace = $symbol->namespace;
            if ($namespace !== null) {
                $this->byNamespace[$namespace][] = $info;
                $this->namespaceSet[$namespace] = true;
            }
        }
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
            } else {
                $merged->metrics[$canonical] = $other->metrics[$canonical];
                $merged->symbolInfos[$canonical] = $info;

                // Update namespace index for new symbols
                $namespace = $info->symbolPath->namespace;
                if ($namespace !== null) {
                    $merged->byNamespace[$namespace][] = $info;
                    $merged->namespaceSet[$namespace] = true;
                }
            }
        }

        return $merged;
    }
}
