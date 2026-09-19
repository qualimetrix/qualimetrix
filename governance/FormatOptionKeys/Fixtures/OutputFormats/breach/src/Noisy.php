<?php

declare(strict_types=1);

namespace Fixture;

/**
 * The committed baseline accepts two occurrences on this method. The third is
 * the breach: a finding is reported as a breach only when its own identity
 * group exceeds its accepted level, so adding a *new* subject would produce a
 * new finding with a null acceptedLevel instead.
 */
final class Noisy
{
    public function emit(): void
    {
        var_dump(1);
        var_dump(2);
        var_dump(3);
    }
}
