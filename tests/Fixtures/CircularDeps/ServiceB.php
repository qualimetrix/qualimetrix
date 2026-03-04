<?php

declare(strict_types=1);

namespace Fixtures\CircularDeps;

/**
 * ServiceB - part of circular dependency chain: ServiceA → ServiceB → ServiceC → ServiceA
 *
 * Expected metrics:
 * - Ca: 1 (ServiceA depends on this)
 * - Ce: 1 (depends on ServiceC)
 * - Forms a cycle of length 3
 */
class ServiceB
{
    private ServiceC $serviceC;

    public function __construct(ServiceC $serviceC)
    {
        $this->serviceC = $serviceC;
    }

    public function processB(array $data): array
    {
        // Transforms and delegates to ServiceC
        $transformed = array_map('strtoupper', $data);
        return $this->serviceC->processC($transformed);
    }

    public function validateB(array $data): bool
    {
        return !empty($data);
    }
}
