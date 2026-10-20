<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Finding\Contract\ProjectScope;

enum ProjectScopeDoor: string
{
    case Paths = 'paths';
    case Exclude = 'exclude';
    case Generated = 'generated';
    case UnknownUniverse = 'unknown-universe';
}
