<?php

declare(strict_types=1);

namespace Fixture;

/**
 * The scenario narrows the run to one rule this class cannot trip, because a
 * project this small trips the coupling rules by construction: with a single
 * class, ClassRank is 1.0 and distance from the main sequence is 1.00.
 */
final class Quiet
{
    public function value(): int
    {
        return 1;
    }
}
