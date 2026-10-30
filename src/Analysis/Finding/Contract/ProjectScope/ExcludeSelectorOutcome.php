<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Finding\Contract\ProjectScope;

enum ExcludeSelectorOutcome: string
{
    case Removed = 'removed';
    case Unmatched = 'unmatched';
    case CoveredBySameSource = 'covered-by-same-source';
    case CoveredByOtherSource = 'covered-by-other-source';
    case Unjudgeable = 'unjudgeable';
    case NotJudged = 'not-judged';
    case InstalledWriter = 'installed-writer';

    /** A conclusion the walk channel reached about the selector, reported or not. */
    public function isJudged(): bool
    {
        return \in_array($this, [self::Removed, self::Unmatched, self::CoveredBySameSource], true);
    }

    /** A selector a report names as skipped: another source hid it, no walk could judge it, or only an installed file wrote it. */
    public function isLeftUnjudged(): bool
    {
        return \in_array($this, [self::NotJudged, self::CoveredByOtherSource, self::InstalledWriter], true);
    }
}
