<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Baseline;

/** Why this run cannot use an absent finding to judge a baseline identity. */
enum RunCoverageGap
{
    case NotMeasured;
}
