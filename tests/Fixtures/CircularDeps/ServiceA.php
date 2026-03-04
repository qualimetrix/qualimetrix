<?php

declare(strict_types=1);

namespace Fixtures\CircularDeps;

/**
 * ServiceA - part of circular dependency chain: ServiceA → ServiceB → ServiceC → ServiceA
 *
 * Expected metrics:
 * - Ca: 1 (ServiceC depends on this)
 * - Ce: 1 (depends on ServiceB)
 * - Forms a cycle of length 3
 */
class ServiceA
{
    private ServiceB $serviceB;

    public function __construct(ServiceB $serviceB)
    {
        $this->serviceB = $serviceB;
    }

    public function processA(array $data): array
    {
        // Delegates to ServiceB
        return $this->serviceB->processB($data);
    }

    public function finalizeA(array $data): array
    {
        // Terminal operation
        return array_merge($data, ['completed_by' => 'ServiceA']);
    }
}
