<?php

declare(strict_types=1);

namespace Qualimetrix\Rules\CodeSmell;

use Qualimetrix\Core\Rule\RuleOptionKey;
use Qualimetrix\Core\Rule\RuleOptionsInterface;
use Qualimetrix\Core\Rule\ThresholdAwareOptionsInterface;
use Qualimetrix\Core\Violation\Severity;
use Qualimetrix\Rules\Support\ThresholdParser;

/**
 * Options for ConstructorOverinjectionRule.
 *
 * Checks the number of constructor parameters (dependencies).
 * Thresholds based on common industry standards:
 * - <= 7 parameters: acceptable
 * - 8+ parameters: warning, consider using a parameter object or splitting responsibilities
 * - 12+ parameters: error, definitely needs refactoring
 */
final readonly class ConstructorOverinjectionOptions implements RuleOptionsInterface, ThresholdAwareOptionsInterface
{
    public function __construct(
        public bool $enabled = true,
        public int $warning = 8,
        public int $error = 12,
    ) {}

    /**
     * @param array<string, mixed> $config
     */
    public static function fromArray(array $config): self
    {
        if ($config === []) {
            return new self(enabled: false);
        }

        $thresholds = ThresholdParser::parse($config, RuleOptionKey::WARNING, RuleOptionKey::ERROR, 8, 12);

        return new self(
            enabled: (bool) ($config[RuleOptionKey::ENABLED] ?? true),
            warning: (int) $thresholds['warning'],
            error: (int) $thresholds['error'],
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

    public function withOverride(int|float|null $warning, int|float|null $error): static
    {
        return new static(
            enabled: $this->enabled,
            warning: $warning !== null ? (int) $warning : $this->warning,
            error: $error !== null ? (int) $error : $this->error,
        );
    }
}
