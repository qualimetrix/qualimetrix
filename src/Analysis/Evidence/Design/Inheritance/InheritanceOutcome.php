<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Design\Inheritance;

enum InheritanceOutcome
{
    case Exact;
    case Floor;
    case Loop;
}
