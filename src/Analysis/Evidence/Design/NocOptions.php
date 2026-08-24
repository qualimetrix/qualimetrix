<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Design;

use Qualimetrix\Analysis\Finding\Contract\Rule\Override\StandardOverrideValidatorTrait;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionKey;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionsInterface;
use Qualimetrix\Analysis\Finding\Contract\Rule\ShorthandOptionKeysInterface;
use Qualimetrix\Analysis\Finding\Contract\Rule\ThresholdAwareOptionsInterface;
use Qualimetrix\Analysis\Finding\Contract\Rule\ThresholdParser;
use Qualimetrix\Analysis\Finding\Contract\Severity;

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
 * - Warning: 10 (many direct children, changes affect many classes)
 * - Error: 15 (too many children, consider using interfaces or composition)
 */
final readonly class NocOptions implements RuleOptionsInterface, ThresholdAwareOptionsInterface, ShorthandOptionKeysInterface
{
    use StandardOverrideValidatorTrait;

    public function __construct(
        public bool $enabled = true,
        public int $warning = 10,
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

        $thresholds = ThresholdParser::parse($config, RuleOptionKey::WARNING, RuleOptionKey::ERROR, 10, 15);

        return new self(
            enabled: (bool) ($config[RuleOptionKey::ENABLED] ?? true),
            warning: (int) $thresholds['warning'],
            error: (int) $thresholds['error'],
        );
    }

    /**
     * @return list<string>
     */
    public static function getShorthandOptionKeys(): array
    {
        return ['threshold'];
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

    public function withOverride(int|float|null $warning, int|float|null $error): static
    {
        return new static(
            enabled: $this->enabled,
            warning: $warning !== null ? (int) $warning : $this->warning,
            error: $error !== null ? (int) $error : $this->error,
        );
    }
}
