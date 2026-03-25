<?php

declare(strict_types=1);

namespace Qualimetrix\Reporting\Health;

/**
 * One contributing metric in a health score breakdown.
 */
final readonly class DecompositionItem
{
    public function __construct(
        public string $metricKey,
        public string $humanName,
        public float $value,
        public string $goodValue,
        public string $direction,
        public string $explanation,
    ) {}
}
