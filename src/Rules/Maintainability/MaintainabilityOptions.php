<?php

declare(strict_types=1);

namespace Qualimetrix\Rules\Maintainability;

use Qualimetrix\Core\Rule\RuleOptionKey;
use Qualimetrix\Core\Rule\RuleOptionsInterface;
use Qualimetrix\Core\Rule\ThresholdAwareOptionsInterface;
use Qualimetrix\Core\Violation\Severity;
use Qualimetrix\Rules\Support\ThresholdParser;

/**
 * Options for MaintainabilityRule.
 *
 * Maintainability Index thresholds:
 * - MI >= 40: good maintainability (no violation)
 * - MI 20-39: moderate maintainability (warning)
 * - MI < 20: poor maintainability (error)
 *
 * Note: Lower MI is worse, so thresholds work in reverse.
 */
final readonly class MaintainabilityOptions implements RuleOptionsInterface, ThresholdAwareOptionsInterface
{
    public function __construct(
        public bool $enabled = true,
        public float $warning = 40.0,
        public float $error = 20.0,
        public bool $excludeTests = true,
        public int $minLoc = 10,
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
            minLoc: (int) ($config['min_loc'] ?? $config['minLoc'] ?? 10),
        );
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
            minLoc: $this->minLoc,
        );
    }
}
