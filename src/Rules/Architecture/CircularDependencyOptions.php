<?php

declare(strict_types=1);

namespace AiMessDetector\Rules\Architecture;

use AiMessDetector\Core\Rule\RuleOptionsInterface;
use AiMessDetector\Core\Violation\Severity;

/**
 * Options for CircularDependencyRule.
 */
final readonly class CircularDependencyOptions implements RuleOptionsInterface
{
    /**
     * @param bool $enabled Whether the rule is enabled
     * @param int $maxCycleSize Maximum cycle size to report (0 = report all)
     * @param bool $directAsError Treat direct cycles (A→B→A) as errors
     */
    public function __construct(
        public bool $enabled = true,
        public int $maxCycleSize = 0,
        public bool $directAsError = true,
    ) {}

    /**
     * @param array<string, mixed> $config
     */
    public static function fromArray(array $config): self
    {
        return new self(
            enabled: (bool) ($config['enabled'] ?? true),
            maxCycleSize: (int) ($config['max_cycle_size'] ?? $config['maxCycleSize'] ?? 0),
            directAsError: (bool) ($config['direct_as_error'] ?? $config['directAsError'] ?? true),
        );
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Returns severity based on cycle size.
     *
     * Small cycles (2-5 classes): error for direct (size ≤2), warning otherwise
     * Medium cycles (6-20 classes): warning
     * Large cycles (21+ classes): warning (too large for error-level urgency)
     */
    public function getSeverity(int|float $value): ?Severity
    {
        $cycleSize = (int) $value;

        // Check if cycle exceeds max size (if configured)
        if ($this->maxCycleSize > 0 && $cycleSize > $this->maxCycleSize) {
            return null; // Too large, don't report
        }

        // Direct cycle (A→B→A) is typically more severe
        if ($cycleSize <= 2 && $this->directAsError) {
            return Severity::Error;
        }

        // Small transitive cycles (3-5) are still actionable warnings
        // Medium and large cycles are always warnings
        return Severity::Warning;
    }
}
