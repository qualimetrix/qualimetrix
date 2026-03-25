<?php

declare(strict_types=1);

namespace Qualimetrix\Core\Symbol;

enum ClassType: string
{
    case Class_ = 'class';
    case Interface_ = 'interface';
    case Trait_ = 'trait';
    case Enum_ = 'enum';
}
