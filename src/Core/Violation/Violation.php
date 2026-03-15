<?php

declare(strict_types=1);

namespace AiMessDetector\Core\Violation;

use AiMessDetector\Core\Rule\RuleLevel;
use AiMessDetector\Core\Symbol\SymbolPath;

final readonly class Violation
{
    /**
     * @param list<Location> $relatedLocations Additional locations (e.g., other copies of duplicated code)
     */
    public function __construct(
        public Location $location,
        public SymbolPath $symbolPath,
        public string $ruleName,
        public string $violationCode,
        public string $message,
        public Severity $severity,
        public int|float|null $metricValue = null,
        public ?RuleLevel $level = null,
        public array $relatedLocations = [],
        public ?string $humanMessage = null,
        public int|float|null $threshold = null,
    ) {}

    /**
     * Returns unique identifier for baseline.
     *
     * Format: ruleName:symbolPath
     *
     * @internal Not used in production code. May be removed in a future version.
     */
    public function getFingerprint(): string
    {
        return \sprintf('%s:%s', $this->ruleName, $this->symbolPath->toCanonical());
    }

    /**
     * Returns the best available human-readable message.
     *
     * Prefers humanMessage when available, falls back to technical message.
     */
    public function getDisplayMessage(): string
    {
        return $this->humanMessage ?? $this->message;
    }
}
