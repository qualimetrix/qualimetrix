<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Git;

/**
 * Git file change status.
 *
 * Represents the type of change a file underwent in git.
 *
 * `T` is a change to the entry's type — a regular file replaced by a symlink
 * or the reverse. Measured: `git diff --name-status -z` reports it for a path
 * that exists and whose content changed, so it is an ordinary change here and
 * carries no distinction downstream. The statuses left out are `U`, which
 * names an unmerged index entry rather than one version of a file, and `X`,
 * which git documents as its own bug; {@see GitClient::parseNameStatus()}
 * refuses both by name rather than silently.
 */
enum ChangeStatus: string
{
    case Added = 'A';
    case Modified = 'M';
    case Deleted = 'D';
    case Renamed = 'R';
    case Copied = 'C';
    case TypeChanged = 'T';
}
