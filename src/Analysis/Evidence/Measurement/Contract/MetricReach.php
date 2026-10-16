<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Measurement\Contract;

/** Whether a metric depends on its members alone or on the measured run. */
enum MetricReach
{
    case Members;
    case Run;
}
