<?php

declare(strict_types=1);

namespace AiMessDetector\Rules\Coupling;

use AiMessDetector\Core\Rule\LevelOptionsInterface;
use AiMessDetector\Core\Violation\Severity;

/**
 * Options for class-level instability checks.
 *
 * Instability range: [0, 1]
 * - 0: maximally stable (only incoming dependencies)
 * - 1: maximally unstable (only outgoing dependencies)
 */
final readonly class ClassInstabilityOptions implements LevelOptionsInterface
{
    public function __construct(
        public bool $enabled = true,
        public float $maxWarning = 0.8,
        public float $maxError = 0.95,
    ) {}

    /**
     * @param array<string, mixed> $config
     */
    public static function fromArray(array $config): self
    {
        // If config is empty, use defaults (all enabled)
        if ($config === []) {
            return new self();
        }

        return new self(
            enabled: (bool) ($config['enabled'] ?? true),
            maxWarning: (float) ($config['max_warning'] ?? 0.8),
            maxError: (float) ($config['max_error'] ?? 0.95),
        );
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function getSeverity(int|float $value): ?Severity
    {
        $instability = (float) $value;

        if ($instability >= $this->maxError) {
            return Severity::Error;
        }

        if ($instability >= $this->maxWarning) {
            return Severity::Warning;
        }

        return null;
    }
}
