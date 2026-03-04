<?php

declare(strict_types=1);

namespace Fixtures\CircularDeps;

/**
 * HelperB - part of simple circular dependency: HelperA → HelperB → HelperA
 *
 * Expected metrics:
 * - Ca: 1 (HelperA depends on this)
 * - Ce: 1 (depends on HelperA)
 * - Forms a cycle of length 2
 * - Completes the bidirectional dependency
 */
class HelperB
{
    private HelperA $helperA;

    public function __construct(HelperA $helperA)
    {
        $this->helperA = $helperA;
    }

    public function convertB(string $value): string
    {
        // Transforms and delegates back to HelperA (creates the cycle!)
        $transformed = str_replace(' ', '_', $value);
        return $this->helperA->normalizeA($transformed);
    }

    public function validateB(string $value): bool
    {
        return \strlen($value) > 0;
    }
}
