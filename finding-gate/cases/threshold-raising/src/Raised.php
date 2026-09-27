<?php

namespace Corpus\ThresholdRaising;

final class Raised
{
    /** @qmx-threshold complexity.ccn warning=6 error=10 */
    public function retuned(int $value): string
    {
        if ($value > 30) { return 'large'; }
        if ($value > 20) { return 'medium'; }
        if ($value > 10) { return 'small'; }
        return 'tiny';
    }

    public function neighbour(int $value): int
    {
        if ($value < 0) { return -1; }
        if ($value === 0) { return 0; }
        if ($value < 10) { return 1; }
        return 2;
    }
}
