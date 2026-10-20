<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Finding\Contract\Rule;

/** An option other than enabled can mute every channel of the producer. */
interface ModeGatedOptionsInterface extends RuleOptionsInterface
{
    public function isMuted(): bool;
}
