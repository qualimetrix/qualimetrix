<?php

declare(strict_types=1);

namespace Qualimetrix\Baseline;

/** Whether the requested symbol belongs to this run, only the baseline, or neither. */
enum BoundaryExplanationStatus
{
    case Current;
    case BaselineOnly;
    case Unknown;
}
