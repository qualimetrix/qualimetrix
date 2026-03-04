<?php

declare(strict_types=1);

namespace AiMessDetector\Rules\Structure;

use AiMessDetector\Core\Rule\RuleOptionsInterface;
use AiMessDetector\Core\Violation\Severity;

/**
 * Options for InheritanceRule.
 *
 * DIT (Depth of Inheritance Tree) thresholds based on Lorenz & Kidd research:
 * - DIT <= 3: good inheritance design (no violation)
 * - DIT = 4-5: moderate depth, review needed (warning)
 * - DIT >= 6: deep hierarchy, likely design issue (error)
 *
 * Deep hierarchies increase coupling and reduce understandability.
 */
final readonly class InheritanceOptions implements RuleOptionsInterface
{
    public function __construct(
        public bool $enabled = true,
        public int $warning = 4,
        public int $error = 6,
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
            warning: (int) ($config['warning'] ?? 4),
            error: (int) ($config['error'] ?? 6),
        );
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Get severity for a given DIT value.
     *
     * Higher DIT = deeper inheritance = more complexity.
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
