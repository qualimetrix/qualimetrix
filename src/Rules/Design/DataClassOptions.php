<?php

declare(strict_types=1);

namespace Qualimetrix\Rules\Design;

use Qualimetrix\Core\Rule\Override\IndependentAxisValidator;
use Qualimetrix\Core\Rule\Override\OverrideValidatorInterface;
use Qualimetrix\Core\Rule\RuleOptionKey;
use Qualimetrix\Core\Rule\RuleOptionsInterface;
use Qualimetrix\Core\Rule\ThresholdAwareOptionsInterface;
use Qualimetrix\Core\Violation\Severity;

/**
 * Options for DataClassRule.
 *
 * A Data Class has high public surface (WOC) but low complexity (WMC),
 * suggesting it only holds data without encapsulating behavior.
 *
 * `@qmx-threshold design.data-class W E` maps W to `wocThreshold`
 * (minimum WOC to flag) and E to `wmcThreshold` (maximum WMC to flag).
 * Both conditions must hold for the rule to flag a class.
 */
final readonly class DataClassOptions implements RuleOptionsInterface, ThresholdAwareOptionsInterface
{
    public function __construct(
        public bool $enabled = true,
        public int $wocThreshold = 80,
        public int $wmcThreshold = 10,
        public int $minMethods = 3,
        public bool $excludeReadonly = true,
        public bool $excludePromotedOnly = true,
        public bool $excludeExceptions = true,
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
            enabled: (bool) ($config[RuleOptionKey::ENABLED] ?? true),
            wocThreshold: (int) ($config['woc_threshold'] ?? $config['wocThreshold'] ?? 80),
            wmcThreshold: (int) ($config['wmc_threshold'] ?? $config['wmcThreshold'] ?? 10),
            minMethods: (int) ($config['min_methods'] ?? $config['minMethods'] ?? 3),
            excludeReadonly: (bool) ($config['exclude_readonly'] ?? $config['excludeReadonly'] ?? true),
            excludePromotedOnly: (bool) ($config['exclude_promoted_only'] ?? $config['excludePromotedOnly'] ?? true),
            excludeExceptions: (bool) ($config['exclude_exceptions'] ?? $config['excludeExceptions'] ?? true),
        );
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Satisfies the interface contract. Severity is determined inline in analyze()
     * because Data Class detection uses multi-metric conditions (WOC + WMC),
     * not a single threshold value.
     */
    public function getSeverity(int|float $value): Severity
    {
        return Severity::Warning;
    }

    /**
     * Maps `@qmx-threshold design.data-class W E` to (`wocThreshold`,
     * `wmcThreshold`). Null keeps the original value per threshold.
     */
    public function withOverride(int|float|null $warning, int|float|null $error): static
    {
        return new static(
            enabled: $this->enabled,
            wocThreshold: $warning !== null ? (int) $warning : $this->wocThreshold,
            wmcThreshold: $error !== null ? (int) $error : $this->wmcThreshold,
            minMethods: $this->minMethods,
            excludeReadonly: $this->excludeReadonly,
            excludePromotedOnly: $this->excludePromotedOnly,
            excludeExceptions: $this->excludeExceptions,
        );
    }

    public static function getOverrideValidator(): OverrideValidatorInterface
    {
        return IndependentAxisValidator::instance();
    }
}
