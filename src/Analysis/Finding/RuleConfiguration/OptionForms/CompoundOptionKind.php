<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Finding\RuleConfiguration\OptionForms;

enum CompoundOptionKind
{
    case List;
    case Map;
    case Union;
}
