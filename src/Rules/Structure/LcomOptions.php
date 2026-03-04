<?php

declare(strict_types=1);

namespace AiMessDetector\Rules\Structure;

use AiMessDetector\Core\Rule\RuleOptionsInterface;
use AiMessDetector\Core\Violation\Severity;

/**
 * Options for LcomRule.
 *
 * LCOM4 (Lack of Cohesion of Methods) thresholds:
 * - LCOM4 = 1: perfectly cohesive (no violation)
 * - LCOM4 = 2: warning (class may have two responsibilities)
 * - LCOM4 >= 3: error (class clearly does too much, should be split)
 *
 * Industry standard: LCOM4 >= 3 indicates serious cohesion problems.
 */
final readonly class LcomOptions implements RuleOptionsInterface
{
    public function __construct(
        public bool $enabled = true,
        public int $warning = 2,
        public int $error = 3,
        public bool $excludeReadonly = true,
        public int $minMethods = 3,
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
            warning: (int) ($config['warning'] ?? 2),
            error: (int) ($config['error'] ?? 3),
            excludeReadonly: (bool) ($config['exclude_readonly'] ?? $config['excludeReadonly'] ?? true),
            minMethods: (int) ($config['min_methods'] ?? $config['minMethods'] ?? 3),
        );
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Get severity for a given LCOM value.
     *
     * Higher LCOM = worse cohesion.
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
