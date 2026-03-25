<?php

declare(strict_types=1);

namespace Qualimetrix\Core\Rule;

enum RuleCategory: string
{
    case Complexity = 'complexity';
    case Size = 'size';
    case Design = 'design';
    case Maintainability = 'maintainability';
    case Coupling = 'coupling';
    case Architecture = 'architecture';
    case CodeSmell = 'code-smell';
    case Security = 'security';
    case Duplication = 'duplication';
}
