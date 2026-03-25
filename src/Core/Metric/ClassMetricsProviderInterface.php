<?php

declare(strict_types=1);

namespace Qualimetrix\Core\Metric;

/**
 * Interface for collectors that provide class-level metrics.
 *
 * Allows Analyzer to extract class metrics without knowing concrete collector types.
 * This maintains proper layer separation: Analysis depends on Core abstractions,
 * not on Metrics implementations.
 */
interface ClassMetricsProviderInterface
{
    /**
     * Returns class-level metrics collected during AST traversal.
     *
     * Should be called after collect() to retrieve detailed class information.
     * The returned data is valid until reset() is called.
     *
     * @return list<ClassWithMetrics>
     */
    public function getClassesWithMetrics(): array;
}
