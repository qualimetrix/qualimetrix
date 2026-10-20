<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Finding\Contract\Rule;

/** Integer bands whose public option fields distinguish a class maximum from method thresholds. */
trait MaximumThresholdOptionsTrait
{
    use IntegerThresholdOptionsTrait;

    protected function integerWarning(): int
    {
        return $this->maxWarning;
    }

    protected function integerError(): int
    {
        return $this->maxError;
    }
}
