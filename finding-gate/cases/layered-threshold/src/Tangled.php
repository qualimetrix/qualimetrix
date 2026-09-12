<?php

declare(strict_types=1);

namespace Corpus\LayeredThreshold;

/**
 * Cyclomatic complexity 6: at or above the `error` the config file's shorthand
 * means, and below the 20 this rule compiles in. This subject is the whole
 * case. It reports `error` only while the half of the band the command line did
 * not rewrite still carries the middle layer's value; a product that lost that
 * half reports `warning` here instead, and the gate sees a severity move.
 */
final class Tangled
{
    public function score(int $value): int
    {
        $total = 0;

        if ($value > 1) {
            ++$total;
        }

        if ($value > 2) {
            ++$total;
        }

        if ($value > 3) {
            ++$total;
        }

        if ($value > 4) {
            ++$total;
        }

        if ($value > 5) {
            ++$total;
        }

        return $total;
    }
}
