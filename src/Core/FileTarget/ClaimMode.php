<?php

declare(strict_types=1);

namespace Qualimetrix\Core\FileTarget;

enum ClaimMode
{
    case Replace;
    case Append;
}
