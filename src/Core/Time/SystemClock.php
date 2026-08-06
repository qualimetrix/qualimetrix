<?php

declare(strict_types=1);

namespace Qualimetrix\Core\Time;

use DateTimeImmutable;

/**
 * The wall clock — the production reading of {@see ClockInterface}.
 *
 * Deliberately trivial: everything interesting about a clock in this project
 * happens in the tests that replace it.
 */
final readonly class SystemClock implements ClockInterface
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable();
    }
}
