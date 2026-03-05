<?php

declare(strict_types=1);

namespace AiMessDetector\Rules\Size;

use AiMessDetector\Core\Rule\RuleOptionsInterface;
use AiMessDetector\Core\Violation\Severity;

/**
 * Options for MethodCountRule.
 *
 * Checks the number of methods in a class.
 * Thresholds based on common industry standards:
 * - <= 10-15 methods: good class size
 * - 20-30 methods: warning, class may be doing too much
 * - > 30 methods: error, class should be split
 */
final readonly class MethodCountOptions implements RuleOptionsInterface
{
    public function __construct(
        public bool $enabled = true,
        public int $warning = 20,
        public int $error = 30,
    ) {}

    /**
     * @param array<string, mixed> $config
     */
    public static function fromArray(array $config): self
    {
        if ($config === []) {
            return new self(enabled: false);
        }

        return new self(
            enabled: (bool) ($config['enabled'] ?? true),
            warning: (int) ($config['warning'] ?? 20),
            error: (int) ($config['error'] ?? 30),
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
