<?php

declare(strict_types=1);

namespace AiMessDetector\Rules\Complexity;

use AiMessDetector\Core\Rule\LevelOptionsInterface;
use AiMessDetector\Core\Violation\Severity;

/**
 * Options for class-level NPath complexity checks (max NPath among methods).
 */
final readonly class ClassNpathComplexityOptions implements LevelOptionsInterface
{
    public function __construct(
        public bool $enabled = false,
        public int $maxWarning = 500,
        public int $maxError = 1000,
    ) {}

    /**
     * @param array<string, mixed> $config
     */
    public static function fromArray(array $config): self
    {
        return new self(
            enabled: (bool) ($config['enabled'] ?? false),
            maxWarning: (int) ($config['max_warning'] ?? $config['maxWarning'] ?? 500),
            maxError: (int) ($config['max_error'] ?? $config['maxError'] ?? 1000),
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
