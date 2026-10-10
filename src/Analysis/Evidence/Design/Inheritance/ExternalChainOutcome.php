<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Design\Inheritance;

enum ExternalChainOutcome
{
    case ReachedRoot;
    case ReachedAnalysedName;
    case NoMapForIt;
    case BrokeAt;
    case Loop;
}
