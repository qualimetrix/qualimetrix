<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\CodeSmell;

use LogicException;

use Qualimetrix\Analysis\Finding\Contract\Rule\BandDirection;
use Qualimetrix\Analysis\Finding\Contract\Rule\NoConfiguredBoundary;
use Qualimetrix\Analysis\Finding\Contract\Rule\Override\StandardOverrideValidatorTrait;
use Qualimetrix\Analysis\Finding\Contract\Rule\ResolvedRuleOptionValues;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionKeySet;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionShape;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionsInterface;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionSurface;
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
 * The declared VO band is separate from the ordinary parameter band; a
 * written `vo-threshold` spreads only to its own warning and error halves.
 */
final readonly class LongParameterListOptions implements RuleOptionsInterface, ThresholdAwareOptionsInterface
{
    use StandardOverrideValidatorTrait;

    public function __construct(
        public bool $enabled = true,
        public int $warning = 4,
        public int $error = 6,
        public int $voWarning = 8,
        public int $voError = 12,
    ) {}

    public static function fromResolved(ResolvedRuleOptionValues $config): self
    {
        $thresholds = ThresholdParser::parse($config, RuleOptionSurface::bandFor(self::class, 'threshold'), 4, 6);
        if (!\is_int($thresholds['warning']) || !\is_int($thresholds['error'])) {
            throw new LogicException('An integer band resolved a non-integer value.');
        }
        $voThresholds = ThresholdParser::parse($config, RuleOptionSurface::bandFor(self::class, 'vo-threshold'), 8, 12);
        if (!\is_int($voThresholds['warning']) || !\is_int($voThresholds['error'])) {
            throw new LogicException('An integer band resolved a non-integer value.');
        }
        return new self(
            enabled: $config->boolean('enabled', true),
            warning: $thresholds['warning'],
            error: $thresholds['error'],
            voWarning: $voThresholds['warning'],
            voError: $voThresholds['error'],
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

    public static function acceptedOptionKeys(): RuleOptionKeySet
    {
        return RuleOptionKeySet::of([
            'error' => RuleOptionShape::integer()->orNull(),
            'threshold' => RuleOptionShape::integer()->orNull(),
            'vo-error' => RuleOptionShape::integer()->orNull(),
            'vo-threshold' => RuleOptionShape::integer()->orNull(),
            'vo-warning' => RuleOptionShape::integer()->orNull(),
            'warning' => RuleOptionShape::integer()->orNull(),
        ])->band('threshold', 'warning', 'error', BandDirection::Rising)->band('vo-threshold', 'vo-warning', 'vo-error', BandDirection::Rising);
    }
}
