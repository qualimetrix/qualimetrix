<?php

declare(strict_types=1);

namespace Fixture\Silent;

/**
 * A namespace and a symbol that carry no finding at all. Without them
 * `hit_empty` — the legitimately empty hit — cannot be built, and the
 * neutral-addition guard has nothing to fire on.
 */
interface SilentContract
{
    public function value(): int;
}
