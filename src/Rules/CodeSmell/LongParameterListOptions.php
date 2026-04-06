<?php

declare(strict_types=1);

namespace Qualimetrix\Rules\CodeSmell;

use Qualimetrix\Core\Rule\RuleOptionKey;
use Qualimetrix\Core\Rule\RuleOptionsInterface;
use Qualimetrix\Core\Rule\ThresholdAwareOptionsInterface;
use Qualimetrix\Core\Violation\Severity;
use Qualimetrix\Rules\Support\ThresholdParser;

/**
 * Options for LongParameterListRule.
 *
 * Checks the number of parameters in a method/function.
 * Thresholds based on common industry standards:
 * - <= 3 parameters: good
 * - 4+ parameters: warning, consider introducing a parameter object
 * - 6+ parameters: error, definitely needs refactoring
 *
 * Readonly Value Object constructors (all promoted properties, empty body) use
 * separate, higher thresholds since many parameters are valid design for typed
 * data containers.
 */
final readonly class LongParameterListOptions implements RuleOptionsInterface, ThresholdAwareOptionsInterface
{
    public function __construct(
        public bool $enabled = true,
        public int $warning = 4,
        public int $error = 6,
        public int $voWarning = 8,
        public int $voError = 12,
    ) {}

    /**
     * @param array<string, mixed> $config
     */
    public static function fromArray(array $config): self
    {
        if ($config === []) {
            return new self(enabled: false);
        }

        $thresholds = ThresholdParser::parse($config, RuleOptionKey::WARNING, RuleOptionKey::ERROR, 4, 6);
        $voThresholds = ThresholdParser::parse($config, 'vo-warning', 'vo-error', 8, 12, 'vo-threshold');

        return new self(
            enabled: (bool) ($config[RuleOptionKey::ENABLED] ?? true),
            warning: (int) $thresholds['warning'],
            error: (int) $thresholds['error'],
            voWarning: (int) $voThresholds['warning'],
            voError: (int) $voThresholds['error'],
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

    /**
     * Returns severity using VO constructor thresholds (higher limits).
     */
    public function getVoSeverity(int|float $value): ?Severity
    {
        if ($value >= $this->voError) {
            return Severity::Error;
        }

        if ($value >= $this->voWarning) {
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
            voWarning: $this->voWarning,
            voError: $this->voError,
        );
    }
}
