<?php

declare(strict_types=1);

namespace AiMessDetector\Core\Metric;

use AiMessDetector\Core\Symbol\SymbolInfo;
use AiMessDetector\Core\Symbol\SymbolType;
use AiMessDetector\Core\Violation\SymbolPath;

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
     * @param int $line The line number (0 for aggregated metrics)
     */
    public function add(SymbolPath $symbol, MetricBag $metrics, string $file, int $line): void;
}
