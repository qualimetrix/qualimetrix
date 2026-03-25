<?php

declare(strict_types=1);

namespace Qualimetrix\Rules\Structure;

use Qualimetrix\Core\Rule\RuleOptionsInterface;
use Qualimetrix\Core\Violation\Severity;
use Qualimetrix\Rules\Support\ThresholdParser;

/**
 * Options for LcomRule.
 *
 * LCOM4 (Lack of Cohesion of Methods) thresholds:
 * - LCOM4 <= 2: cohesive class (no violation)
 * - LCOM4 3-4: warning (class may have multiple responsibilities)
 * - LCOM4 >= 5: error (class clearly does too much, should be split)
 *
 * Industry standard: LCOM4 >= 5 indicates serious cohesion problems.
 */
final readonly class LcomOptions implements RuleOptionsInterface
{
    public function __construct(
        public bool $enabled = true,
        public int $warning = 3,
        public int $error = 5,
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

        $thresholds = ThresholdParser::parse($config, 'warning', 'error', 3, 5);

        return new self(
            enabled: (bool) ($config['enabled'] ?? true),
            warning: (int) $thresholds['warning'],
            error: (int) $thresholds['error'],
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
