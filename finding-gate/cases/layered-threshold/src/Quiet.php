<?php

declare(strict_types=1);

namespace Corpus\LayeredThreshold;

/** Cyclomatic complexity 1: below both halves of the band, so it reports nothing. */
final class Quiet
{
    public function total(int $left, int $right): int
    {
        return $left + $right;
    }
}
