<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Run\Contract\Configuration;

/** Only covered and a whole-root unknown run may judge whole-project channels. */
enum ProjectScopeState: string
{
    case Covered = 'covered';
    case Narrowed = 'narrowed';
    case Unknown = 'unknown';
    case Unmeasured = 'unmeasured';

    public function coversProjectScope(): bool
    {
        return $this === self::Covered || $this === self::Unknown;
    }
}
