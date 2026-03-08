<?php

declare(strict_types=1);

namespace AiMessDetector\Rules\Complexity;

use AiMessDetector\Core\Rule\LevelOptionsInterface;
use AiMessDetector\Core\Violation\Severity;

/**
 * Options for class-level complexity checks.
 *
 * Checks maximum CCN among class methods.
 */
final readonly class ClassComplexityOptions implements LevelOptionsInterface
{
    public function __construct(
        public bool $enabled = true,
        public int $maxWarning = 30,
        public int $maxError = 50,
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
            maxWarning: (int) ($config['max_warning'] ?? $config['maxWarning'] ?? 30),
            maxError: (int) ($config['max_error'] ?? $config['maxError'] ?? 50),
        );
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function getSeverity(int|float $value): ?Severity
    {
        if ($value >= $this->maxError) {
            return Severity::Error;
        }

        if ($value >= $this->maxWarning) {
            return Severity::Warning;
        }

        return null;
    }
}
