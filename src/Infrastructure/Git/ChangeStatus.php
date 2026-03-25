<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Git;

/**
 * Git file change status.
 *
 * Represents the type of change a file underwent in git.
 */
enum ChangeStatus: string
{
    case Added = 'A';
    case Modified = 'M';
    case Deleted = 'D';
    case Renamed = 'R';
    case Copied = 'C';
}
