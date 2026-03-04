<?php

declare(strict_types=1);

namespace AiMessDetector\Core\Violation;

use AiMessDetector\Core\Rule\RuleLevel;

final readonly class Violation
{
    public function __construct(
        public Location $location,
        public SymbolPath $symbolPath,
        public string $ruleName,
        public string $message,
        public Severity $severity,
        public int|float|null $metricValue = null,
        public ?RuleLevel $level = null,
    ) {}

    /**
     * Returns unique identifier for baseline.
     *
     * Format: ruleName:symbolPath
     */
    public function getFingerprint(): string
    {
        return \sprintf('%s:%s', $this->ruleName, $this->symbolPath->toCanonical());
    }
}
