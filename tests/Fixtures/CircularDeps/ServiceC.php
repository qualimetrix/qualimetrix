<?php

declare(strict_types=1);

namespace Fixtures\CircularDeps;

/**
 * ServiceC - part of circular dependency chain: ServiceA → ServiceB → ServiceC → ServiceA
 *
 * Expected metrics:
 * - Ca: 1 (ServiceB depends on this)
 * - Ce: 1 (depends on ServiceA)
 * - Forms a cycle of length 3
 * - Completes the circular dependency
 */
class ServiceC
{
    private ServiceA $serviceA;

    public function __construct(ServiceA $serviceA)
    {
        $this->serviceA = $serviceA;
    }

    public function processC(array $data): array
    {
        // Filters and delegates back to ServiceA (creates the cycle!)
        $filtered = array_filter($data, fn($item) => $item !== null);
        return $this->serviceA->finalizeA($filtered);
    }

    public function enrichC(array $data): array
    {
        return array_merge($data, ['enriched' => true]);
    }
}
