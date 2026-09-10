<?php

declare(strict_types=1);

namespace Fixture\Quiet;

/**
 * Deliberately free of findings: without a namespace and a class that a
 * scoping door can legitimately narrow to nothing, `hit_empty` cannot be
 * built, and with it the neutral-addition calibration case cannot be built.
 */
final class QuietClass
{
    public function value(): int
    {
        return 1;
    }
}
