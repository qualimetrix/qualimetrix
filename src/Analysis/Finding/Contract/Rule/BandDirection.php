<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Finding\Contract\Rule;

enum BandDirection
{
    case Rising;
    case Falling;
}
