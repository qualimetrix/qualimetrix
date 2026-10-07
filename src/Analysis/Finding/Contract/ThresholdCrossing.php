<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Finding\Contract;

use LogicException;

/**
 * Wording for a raw numeric value against its selected effective threshold.
 * Rendered values may look equal even when the raw value strictly exceeds it.
 */
enum ThresholdCrossing: string
{
    case Reaches = 'reaches';
    case Exceeds = 'exceeds';

    public static function of(int|float $value, int|float $threshold): self
    {
        if ($value < $threshold) {
            throw new LogicException('A value below its threshold has no crossing.');
        }

        return $value > $threshold ? self::Exceeds : self::Reaches;
    }
}
