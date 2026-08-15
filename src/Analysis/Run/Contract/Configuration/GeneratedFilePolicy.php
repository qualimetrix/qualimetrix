<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Run\Contract\Configuration;

enum GeneratedFilePolicy
{
    case Include;
    case Exclude;
}
