<?php

declare(strict_types=1);

namespace Corpus\ComputedCrossLevel;

final class MetricSource
{
    public function measured(bool $flag): int
    {
        return $flag ? 1 : 0;
    }
}
