<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Configuration\Contract\Document\Schema;

use Closure;

final readonly class IntegerJudgement
{
    /** @param Closure(int): ?string $judge */
    public function __construct(private Closure $judge) {}

    public function refusal(int $value): ?string
    {
        return ($this->judge)($value);
    }
}
