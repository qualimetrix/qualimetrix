<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Maintainability;

use Qualimetrix\Analysis\Finding\Contract\Rule\Override\InvertedOverrideValidator;
use Qualimetrix\Analysis\Finding\Contract\Rule\Override\OverrideValidatorInterface;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionKey;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionsInterface;
use Qualimetrix\Analysis\Finding\Contract\Rule\ShorthandOptionKeysInterface;
use Qualimetrix\Analysis\Finding\Contract\Rule\ThresholdAwareOptionsInterface;
use Qualimetrix\Analysis\Finding\Contract\Rule\ThresholdParser;
use Qualimetrix\Analysis\Finding\Contract\Severity;

/**
 * Options for MaintainabilityRule.
 *
 * Maintainability Index thresholds:
 * - MI >= 40: good maintainability (no finding)
 * - MI 20-39: moderate maintainability (warning)
 * - MI < 20: poor maintainability (error)
 *
 * Note: Lower MI is worse, so thresholds work in reverse.
 */
final readonly class MaintainabilityOptions implements RuleOptionsInterface, ThresholdAwareOptionsInterface, ShorthandOptionKeysInterface
{
    public function __construct(
        public bool $enabled = true,
        public float $warning = 40.0,
        public float $error = 20.0,
        public bool $excludeTests = true,
        public int $minStatements = 10,
    ) {}

    /**
     * @param array<string, mixed> $config
     */
    public static function fromArray(array $config): self
    {
        if ($config === []) {
            return new self(enabled: false);
        }

        $thresholds = ThresholdParser::parse($config, RuleOptionKey::WARNING, RuleOptionKey::ERROR, 40.0, 20.0);

        return new self(
            enabled: (bool) ($config[RuleOptionKey::ENABLED] ?? true),
            warning: (float) $thresholds['warning'],
            error: (float) $thresholds['error'],
            excludeTests: (bool) ($config['exclude_tests'] ?? $config['excludeTests'] ?? true),
            minStatements: (int) ($config['min_statements'] ?? $config['minStatements'] ?? 10),
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
     * Get severity for a given MI value.
     *
     * Lower MI = worse maintainability.
     */
    public function getSeverity(int|float $value): ?Severity
    {
        // Design decision: strict `<` is intentional (not `<=`).
        // The threshold is the first "acceptable" value for the better category:
        // MI=20.0 is a warning (not error), MI=40.0 is good (not warning).
        if ($value < $this->error) {
            return Severity::Error;
        }

        if ($value < $this->warning) {
            return Severity::Warning;
        }

        return null;
    }

    public function withOverride(int|float|null $warning, int|float|null $error): static
    {
        return new static(
            enabled: $this->enabled,
            warning: $warning !== null ? (float) $warning : $this->warning,
            error: $error !== null ? (float) $error : $this->error,
            excludeTests: $this->excludeTests,
            minStatements: $this->minStatements,
        );
    }

    public static function getOverrideValidator(): OverrideValidatorInterface
    {
        return InvertedOverrideValidator::instance();
    }
}
