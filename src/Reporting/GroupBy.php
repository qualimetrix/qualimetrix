<?php

declare(strict_types=1);

namespace Qualimetrix\Reporting;

enum GroupBy: string
{
    case None = 'none';
    case File = 'file';
    case Rule = 'rule';
    case Severity = 'severity';
}
