<?php

declare(strict_types=1);

/**
 * Global namespace class — edge case: class without namespace.
 *
 * Tests that global namespace handling doesn't break parent NS logic.
 *
 * Method-level CCN:
 * - format(): CCN=2 (base 1 + 1 ternary)
 *
 * Class-level:
 * - ccn.sum = 2, ccn.max = 2, ccn.avg = 2.0
 * - methodCount = 1
 * - propertyCount = 0
 * - classCount = 1 (file-level)
 */
class GlobalHelper
{
    /**
     * CCN = 2 (base 1 + 1 ternary).
     */
    public static function format(mixed $value): string
    {
        return is_array($value) ? implode(', ', $value) : (string) $value;
    }
}
