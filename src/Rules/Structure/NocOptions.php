<?php

declare(strict_types=1);

namespace AiMessDetector\Rules\Structure;

use AiMessDetector\Core\Rule\RuleOptionsInterface;
use AiMessDetector\Core\Violation\Severity;

/**
 * Configuration options for NOC (Number of Children) rule.
 *
 * NOC measures how many classes directly extend a given class.
 * High NOC indicates:
 * - Wide reuse/inheritance
 * - High impact of changes (affects many subclasses)
 * - Potential violation of Liskov Substitution Principle
 *
 * Thresholds based on Chidamber & Kemerer research:
 * - Warning: 7 (many direct children, changes affect many classes)
 * - Error: 15 (too many children, consider using interfaces or composition)
 */
final readonly class NocOptions implements RuleOptionsInterface
{
    public function __construct(
        public bool $enabled = true,
        public int $warning = 7,
        public int $error = 15,
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
            warning: (int) ($config['warning'] ?? 7),
            error: (int) ($config['error'] ?? 15),
        );
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Get severity for a given NOC value.
     *
     * Higher NOC = more children = wider impact of changes.
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
