<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Finding\Contract\Rule\Override;

enum OverrideSyntax
{
    case Shorthand;
    case ExplicitAxes;
}
