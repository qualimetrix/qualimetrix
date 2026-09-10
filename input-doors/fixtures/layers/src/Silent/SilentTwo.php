<?php

declare(strict_types=1);

namespace Fixture\Silent;

/** Padding: ClassRank is relative, so a small graph flags every class in it. */
final class SilentTwo implements SilentContract
{
    public function value(): int
    {
        return 1;
    }
}
