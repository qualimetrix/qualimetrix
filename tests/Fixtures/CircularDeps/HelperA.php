<?php

declare(strict_types=1);

namespace Fixtures\CircularDeps;

/**
 * HelperA - part of simple circular dependency: HelperA → HelperB → HelperA
 *
 * Expected metrics:
 * - Ca: 1 (HelperB depends on this)
 * - Ce: 1 (depends on HelperB)
 * - Forms a cycle of length 2 (simple bidirectional dependency)
 */
class HelperA
{
    private HelperB $helperB;

    public function __construct(HelperB $helperB)
    {
        $this->helperB = $helperB;
    }

    public function convertA(string $value): string
    {
        // Uses HelperB for conversion
        return $this->helperB->convertB($value);
    }

    public function normalizeA(string $value): string
    {
        // Terminal operation
        return strtolower(trim($value));
    }
}
