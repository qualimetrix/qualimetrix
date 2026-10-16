<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Design\Inheritance;

/** Whether the measured ancestry proves or disproves Throwable membership. */
enum ThrowableReach
{
    case Yes;
    case No;
    case Unknown;
}
