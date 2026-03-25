<?php

declare(strict_types=1);

namespace Qualimetrix\Core\Metric;

use Qualimetrix\Core\Symbol\SymbolInfo;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Core\Symbol\SymbolType;

interface MetricRepositoryInterface
{
    /**
     * Returns metrics for any symbol.
     *
     * All symbol levels (Method, Class, File, Namespace, Project) return MetricBag.
     * Aggregated metrics use naming convention: {metric}.{strategy} (e.g., ccn.sum, loc.avg).
     */
    public function get(SymbolPath $symbol): MetricBag;

    /**
     * Returns iterator over symbols of given type.
     *
     * @return iterable<SymbolInfo>
     */
    public function all(SymbolType $type): iterable;

    /**
     * Checks if metrics exist for given symbol.
     */
    public function has(SymbolPath $symbol): bool;

    /**
     * Adds or merges metrics for a symbol.
     *
     * If the symbol already has metrics, new metrics are merged (new values override).
     *
     * @param SymbolPath $symbol The symbol to add metrics for
     * @param MetricBag $metrics The metrics to add
     * @param string $file The source file path
     * @param ?int $line The line number (null for aggregated/namespace metrics)
     */
    public function add(SymbolPath $symbol, MetricBag $metrics, string $file, ?int $line): void;

    /**
     * Adds a single scalar metric to an existing symbol.
     *
     * Unlike add(), this only touches scalar metrics and never duplicates
     * DataBag entries. Use this when you need to enrich existing symbols
     * with computed metrics (e.g., in global collectors).
     *
     * If the symbol does not exist, the metric is silently ignored.
     */
    public function addScalar(SymbolPath $symbol, string $key, int|float $value): void;

    /**
     * Returns all namespaces that have metrics.
     *
     * @return list<string>
     */
    public function getNamespaces(): array;

    /**
     * Returns all metrics for symbols in a given namespace.
     *
     * @return list<SymbolInfo>
     */
    public function forNamespace(string $namespace): array;
}
