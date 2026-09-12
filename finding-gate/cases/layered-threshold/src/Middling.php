<?php

declare(strict_types=1);

namespace Corpus\LayeredThreshold;

/**
 * Cyclomatic complexity 3: at or above the `warning` the command line wrote,
 * below the `error` the config file's shorthand means. It reports the same
 * severity whether or not the band's other half survives, which is what makes
 * it the control beside `Tangled`.
 */
final class Middling
{
    public function classify(int $value): string
    {
        if ($value < 0) {
            return 'negative';
        }

        if ($value === 0) {
            return 'zero';
        }

        return 'positive';
    }
}
