<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Finding\Contract;

/** Evidence on which a channel's reported value depends. */
enum ValueReach
{
    case Members;
    case Run;
}
