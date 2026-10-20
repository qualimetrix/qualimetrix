<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Finding\Contract\Selection;

/** The authored enablement value, independent of publication admission. */
enum CellSwitch
{
    case On;
    case Off;
}
