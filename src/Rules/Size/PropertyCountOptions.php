<?php

declare(strict_types=1);

namespace AiMessDetector\Rules\Size;

use AiMessDetector\Core\Rule\RuleOptionsInterface;
use AiMessDetector\Core\Violation\Severity;

/**
 * Options for the property count rule.
 */
final readonly class PropertyCountOptions implements RuleOptionsInterface
{
    public function __construct(
        public bool $enabled = true,
        public int $warning = 15,
        public int $error = 20,
        public bool $excludeReadonly = true,
        public bool $excludePromotedOnly = true,
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
            warning: (int) ($config['warning'] ?? 15),
            error: (int) ($config['error'] ?? 20),
            excludeReadonly: (bool) ($config['exclude_readonly'] ?? $config['excludeReadonly'] ?? true),
            excludePromotedOnly: (bool) ($config['exclude_promoted_only'] ?? $config['excludePromotedOnly'] ?? true),
        );
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Get severity for a given property count value.
     */
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
