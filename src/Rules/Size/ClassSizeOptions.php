<?php

declare(strict_types=1);

namespace AiMessDetector\Rules\Size;

use AiMessDetector\Core\Rule\LevelOptionsInterface;
use AiMessDetector\Core\Violation\Severity;

/**
 * Options for class-level size checks.
 *
 * Checks the number of methods in a class.
 * Thresholds based on common industry standards:
 * - <= 10-15 methods: good class size
 * - 15-20 methods: warning, class may be doing too much
 * - > 20 methods: error, class should be split
 */
final readonly class ClassSizeOptions implements LevelOptionsInterface
{
    public function __construct(
        public bool $enabled = true,
        public int $warning = 15,
        public int $error = 20,
    ) {}

    /**
     * @param array<string, mixed> $config
     */
    public static function fromArray(array $config): self
    {
        return new self(
            enabled: (bool) ($config['enabled'] ?? true),
            warning: (int) ($config['warning'] ?? $config['warningThreshold'] ?? 15),
            error: (int) ($config['error'] ?? $config['errorThreshold'] ?? 20),
        );
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function getSeverity(int|float $value): ?Severity
    {
        if ($value >= $this->error) {
            return Severity::Error;
        }

        if ($value >= $this->warning) {
            return Severity::Warning;
        }

        return null;
    }
}
