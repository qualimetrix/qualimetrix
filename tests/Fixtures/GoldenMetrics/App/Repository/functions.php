<?php

declare(strict_types=1);

namespace GoldenMetrics\App\Repository;

/**
 * Standalone function — tests function aggregation path.
 *
 * Functions are counted in symbolMethodCount and aggregate CCN
 * directly to namespace level (skip class aggregation).
 *
 * CCN = 4 (base 1 + 1 if + 1 foreach + 1 if inside foreach)
 */
function findFirstMatch(array $items, string $key): mixed
{
    if (empty($items)) {
        return null;
    }

    foreach ($items as $item) {
        if (isset($item[$key])) {
            return $item[$key];
        }
    }

    return null;
}
