<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Finding\Contract\Selection;

/** Whether the selection filter directly admits this declared cell. */
enum CellAdmission
{
    case Direct;
    case Filtered;
}
