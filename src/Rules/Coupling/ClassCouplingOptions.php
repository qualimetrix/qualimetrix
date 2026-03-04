<?php

declare(strict_types=1);

namespace AiMessDetector\Rules\Coupling;

use AiMessDetector\Core\Rule\LevelOptionsInterface;
use AiMessDetector\Core\Violation\Severity;

/**
 * Options for class-level coupling checks.
 *
 * Instability range: [0, 1]
 * - 0: maximally stable (only incoming dependencies)
 * - 1: maximally unstable (only outgoing dependencies)
 *
 * CBO (Coupling Between Objects) = Ca + Ce
 * - Low CBO (≤14): weakly coupled, easy to test
 * - Medium CBO (15-20): acceptable
 * - High CBO (>20): tightly coupled, hard to isolate
 */
final readonly class ClassCouplingOptions implements LevelOptionsInterface
{
    public function __construct(
        public bool $enabled = true,
        public float $maxInstabilityWarning = 0.8,
        public float $maxInstabilityError = 0.95,
        public int $cboWarningThreshold = 14,
        public int $cboErrorThreshold = 20,
    ) {}

    /**
     * @param array<string, mixed> $config
     */
    public static function fromArray(array $config): self
    {
        // If config is empty, level is disabled
        if ($config === []) {
            return new self(enabled: false);
        }

        return new self(
            enabled: (bool) ($config['enabled'] ?? true),
            maxInstabilityWarning: (float) ($config['max_instability_warning'] ?? 0.8),
            maxInstabilityError: (float) ($config['max_instability_error'] ?? 0.95),
            cboWarningThreshold: (int) ($config['cbo_warning_threshold'] ?? 14),
            cboErrorThreshold: (int) ($config['cbo_error_threshold'] ?? 20),
        );
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function getSeverity(int|float $value): ?Severity
    {
        $instability = (float) $value;

        if ($instability >= $this->maxInstabilityError) {
            return Severity::Error;
        }

        if ($instability >= $this->maxInstabilityWarning) {
            return Severity::Warning;
        }

        return null;
    }
}
