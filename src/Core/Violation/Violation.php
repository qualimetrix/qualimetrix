<?php

declare(strict_types=1);

namespace Qualimetrix\Core\Violation;

use Qualimetrix\Core\Dependency\DependencyType;
use Qualimetrix\Core\Rule\RuleLevel;
use Qualimetrix\Core\Symbol\SymbolPath;

/**
 * @qmx-threshold code-smell.constructor-overinjection error=16 — Violation is a flat domain VO; its constructor parameters mirror its public surface and bundling would obscure their independence
 */
final readonly class Violation
{
    /**
     * @param list<Location> $relatedLocations Additional locations (e.g., other copies of duplicated code)
     * @param ?SymbolPath $dependencyTarget Target symbol of the offending dependency edge (for dependency-based rules)
     * @param ?DependencyType $dependencyType Type of the offending dependency edge (for dependency-based rules)
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
        public ?string $recommendation = null,
        public int|float|null $threshold = null,
        public ?SymbolPath $dependencyTarget = null,
        public ?DependencyType $dependencyType = null,
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
     * Prefers recommendation when available, falls back to technical message.
     */
    public function getDisplayMessage(): string
    {
        return $this->recommendation ?? $this->message;
    }
}
