<?php

declare(strict_types=1);

namespace Qualimetrix\Core\Metric;

/**
 * Interface for collectors that provide namespace-owned metric contributions.
 *
 * Allows Analysis to register metrics for every namespace block without
 * knowing concrete collector types.
 */
interface NamespaceMetricProviderInterface
{
    /**
     * Returns namespace-level metrics collected during AST traversal.
     *
     * The returned data is valid until reset() is called.
     *
     * @return list<NamespaceWithMetrics>
     */
    public function getNamespacesWithMetrics(): array;
}
