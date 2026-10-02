<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Run\Contract\Discovery;

enum ProjectEntryPresence: string
{
    case Present = 'present';
    case Absent = 'absent';
    case Unknown = 'unknown';
}
