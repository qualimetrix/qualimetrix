<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\CodeSmell;

use Qualimetrix\Analysis\Finding\Contract\Rule\NoConfiguredBoundary;
use Qualimetrix\Analysis\Finding\Contract\Rule\Override\StandardOverrideValidatorTrait;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionKey;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionsInterface;
use Qualimetrix\Analysis\Finding\Contract\Rule\ShorthandOptionKeysInterface;
use Qualimetrix\Analysis\Finding\Contract\Rule\ThresholdAwareOptionsInterface;
use Qualimetrix\Analysis\Finding\Contract\Rule\ThresholdParser;
use Qualimetrix\Analysis\Finding\Contract\Severity;

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
 *
 * > **Note:** The canonical spelling for the `vo-*` options is kebab-case
 * > (`vo-warning`, `vo-error`, `vo-threshold`) — that's what users type in
 * > `qmx.yaml`, presets, and `--rule-opt`. `Qualimetrix\Analysis\Finding\RuleConfiguration\RuleOptionsFactory`
 * > (config-file keys) and `Qualimetrix\Analysis\Finding\RuleConfiguration\RuleOptionsParser`
 * > (`--rule-opt` keys) normalize any kebab-case/snake_case key to camelCase
 * > before it reaches {@see fromArray()}, so `fromArray()` must also accept the
 * > camelCase forms (`voWarning`, `voError`, `voThreshold`) — that's the form
 * > actually arriving through those two channels. Both spellings are kept
 * > working via `ThresholdParser::parse()`'s `legacyKeys` argument below.
 */
final readonly class LongParameterListOptions implements RuleOptionsInterface, ThresholdAwareOptionsInterface, ShorthandOptionKeysInterface
{
    use StandardOverrideValidatorTrait;

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
        $voThresholds = ThresholdParser::parse(
            $config,
            'vo-warning',
            'vo-error',
            8,
            12,
            'vo-threshold',
            legacyKeys: ['warning' => ['voWarning'], 'error' => ['voError'], 'threshold' => ['voThreshold']],
        );

        return new self(
            enabled: (bool) ($config[RuleOptionKey::ENABLED] ?? true),
            warning: (int) $thresholds['warning'],
            error: (int) $thresholds['error'],
            voWarning: (int) $voThresholds['warning'],
            voError: (int) $voThresholds['error'],
        );
    }

    /**
     * @return list<string>
     */
    public static function getShorthandOptionKeys(): array
    {
        return ['threshold', 'vo-threshold'];
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

    /**
     * Returns a copy with overridden thresholds for the VO-constructor branch.
     *
     * `@qmx-threshold` only carries one warning/error pair. This method keeps
     * the regular-method pair intact while projecting that pair onto the VO
     * thresholds at the VO-specific call site.
     */
    public function withVoOverride(int|float|null $warning, int|float|null $error): static
    {
        return new static(
            enabled: $this->enabled,
            warning: $this->warning,
            error: $this->error,
            voWarning: $warning !== null ? (int) $warning : $this->voWarning,
            voError: $error !== null ? (int) $error : $this->voError,
        );
    }

    /**
     * A value object's constructor is judged against `voWarning`, an ordinary
     * callable against `warning`, and the choice is made from the subject, not
     * from anything the caller asks with.
     */
    public function warningBoundary(): NoConfiguredBoundary
    {
        return NoConfiguredBoundary::MoreThanOneBoundary;
    }
}
