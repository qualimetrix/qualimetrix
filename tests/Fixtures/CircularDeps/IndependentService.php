<?php

declare(strict_types=1);

namespace Fixtures\CircularDeps;

/**
 * IndependentService - control sample with NO circular dependencies
 *
 * Expected metrics:
 * - Ca: 0 (no one depends on this)
 * - Ce: 0 (depends on nothing)
 * - Instability: N/A (0 / 0)
 * - No cycles detected
 */
class IndependentService
{
    public function process(array $data): array
    {
        // Simple transformation without external dependencies
        return array_map(fn($item) => strtoupper($item), $data);
    }

    public function validate(array $data): bool
    {
        return \count($data) > 0;
    }

    public function normalize(array $data): array
    {
        return array_values(array_unique($data));
    }
}
