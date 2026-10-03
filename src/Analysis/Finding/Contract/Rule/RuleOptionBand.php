<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Finding\Contract\Rule;

final readonly class RuleOptionBand
{
    public function __construct(public string $shorthand, public string $warning, public string $error, public BandDirection $direction = BandDirection::Rising) {}
}
