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
}
