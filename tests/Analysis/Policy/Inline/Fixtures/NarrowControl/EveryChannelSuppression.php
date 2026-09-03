<?php

declare(strict_types=1);

namespace Fixtures\NarrowControl;

/**
 * A suppression that names no rule, which is the one directive the audit
 * refuses to judge for having nothing to consult.
 *
 * Alone in its file on purpose. It is the only seeded directive carrying no
 * `@qmx-threshold`, so it is the only one the threshold enumeration cannot see
 * — and a file this fixture could leak into `src/` while every threshold-shaped
 * barrier stayed green. Under `src/` it would silence every rule on this class
 * in `check`, in the suppression snapshot and in the ratchet at once, which is
 * why the barrier that covers it is a whole-file one.
 *
 * @qmx-ignore * -- addresses-every-channel: no rule filter, so no producer to ask.
 */
final class EveryChannelSuppression
{
    public function value(): int
    {
        return 1;
    }
}
