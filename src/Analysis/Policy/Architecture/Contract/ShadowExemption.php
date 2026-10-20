<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Architecture\Contract;

/** Why an established later match is legitimate under first-match precedence. */
enum ShadowExemption: string
{
    case NarrowerDeclaredFirst = 'narrower-declared-first';
    case ReceivesWhatItLeaves = 'receives-what-it-leaves';
    case NonPatternPrecedence = 'non-pattern-precedence';
}
