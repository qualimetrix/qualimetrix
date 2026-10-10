<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Configuration\Contract\Document\Schema;

enum BareElementPolicy
{
    case ListOnly;
    case SingleAllowed;
}
