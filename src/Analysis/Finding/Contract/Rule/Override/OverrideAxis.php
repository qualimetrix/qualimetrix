<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Finding\Contract\Rule\Override;

enum OverrideAxis: string
{
    case Warning = 'warning';
    case Error = 'error';
}
