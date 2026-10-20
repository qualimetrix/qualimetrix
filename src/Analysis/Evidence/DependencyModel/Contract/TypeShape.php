<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\DependencyModel\Contract;

enum TypeShape: string
{
    case Single = 'single';
    case Nullable = 'nullable';
    case Union = 'union';
    case Intersection = 'intersection';
    case Dnf = 'dnf';
}
