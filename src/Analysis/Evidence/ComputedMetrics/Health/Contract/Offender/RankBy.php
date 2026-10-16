<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Contract\Offender;

enum RankBy: string
{
    case Score = 'score';
    case Density = 'density';
}
