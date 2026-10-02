<?php

declare(strict_types=1);

namespace Qualimetrix\Core\FileTarget;

enum TargetKind
{
    case Regular;
    case Absent;
    case Stream;
    case Descriptor;
}
