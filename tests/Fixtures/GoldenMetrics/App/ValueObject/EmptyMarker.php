<?php

declare(strict_types=1);

namespace GoldenMetrics\App\ValueObject;

/**
 * Empty marker class — edge case: class without methods.
 *
 * Tests behavior when symbolMethodCount = 0 for a class.
 * Should still be counted in classCount and symbolClassCount.
 *
 * Class-level:
 * - ccn.sum = 0 (no methods)
 * - methodCount = 0
 * - propertyCount = 1
 * - classCount = 1 (file-level)
 */
class EmptyMarker
{
    public string $label = 'marker';
}
