<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Finding\Contract\Rule;

use Qualimetrix\Analysis\Finding\Contract\Rule\OptionLanguage\ThresholdPair;
use Qualimetrix\Analysis\Finding\Contract\Severity;

/** Queries over an enabled option's metric-owned numeric band. */
trait ThresholdOptionQueries
{
    abstract protected function thresholdPair(): ThresholdPair;

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function getSeverity(int|float $value): ?Severity
    {
        return $this->thresholdPair()->severity($value);
    }

    public function warningBoundary(): int|float
    {
        return $this->thresholdPair()->warning;
    }
}
