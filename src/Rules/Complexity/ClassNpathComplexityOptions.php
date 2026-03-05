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
        public int $max_warning = 200,
        public int $max_error = 1000,
    ) {}

    /**
     * @param array<string, mixed> $config
     */
    public static function fromArray(array $config): self
    {
        return new self(
            enabled: (bool) ($config['enabled'] ?? false),
            max_warning: (int) ($config['max_warning'] ?? 200),
            max_error: (int) ($config['max_error'] ?? 1000),
        );
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function getSeverity(int|float $value): ?Severity
    {
        if ($value >= $this->max_error) {
            return Severity::Error;
        }

        if ($value >= $this->max_warning) {
            return Severity::Warning;
        }

        return null;
    }
}
