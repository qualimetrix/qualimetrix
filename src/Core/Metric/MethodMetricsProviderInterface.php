<?php

declare(strict_types=1);

namespace AiMessDetector\Core\Metric;

/**
 * Interface for collectors that provide method-level metrics.
 *
 * Allows Analyzer to extract method metrics without knowing concrete collector types.
 * This maintains proper layer separation: Analysis depends on Core abstractions,
 * not on Metrics implementations.
 */
interface MethodMetricsProviderInterface
{
    /**
     * Returns method-level metrics collected during AST traversal.
     *
     * Should be called after collect() to retrieve detailed method information.
     * The returned data is valid until reset() is called.
     *
     * @return list<MethodWithMetrics>
     */
    public function getMethodsWithMetrics(): array;
}
