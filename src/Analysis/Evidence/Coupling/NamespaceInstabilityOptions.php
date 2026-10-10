<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Coupling;

use Qualimetrix\Analysis\Finding\Contract\Rule\BandDirection;

use Qualimetrix\Analysis\Finding\Contract\Rule\LevelOptionsInterface;
use Qualimetrix\Analysis\Finding\Contract\Rule\Override\StandardOverrideValidatorTrait;
use Qualimetrix\Analysis\Finding\Contract\Rule\ResolvedRuleOptionValues;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionKeySet;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionShape;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionSurface;
use Qualimetrix\Analysis\Finding\Contract\Rule\ThresholdAwareOptionsInterface;
use Qualimetrix\Analysis\Finding\Contract\Rule\ThresholdParser;
use Qualimetrix\Analysis\Finding\Contract\Severity;

/**
 * Options for namespace-level instability checks.
 *
 * Instability range: [0, 1]
 * - 0: maximally stable (only incoming dependencies)
 * - 1: maximally unstable (only outgoing dependencies)
 */
final readonly class NamespaceInstabilityOptions implements LevelOptionsInterface, ThresholdAwareOptionsInterface
{
    use StandardOverrideValidatorTrait;

    public function __construct(
        public bool $enabled = true,
        public float $maxWarning = 0.8,
        public float $maxError = 0.95,
        public int $minClassCount = 3,
        public int $minAfferent = 1,
    ) {}

    public static function fromResolved(ResolvedRuleOptionValues $config): self
    {
        $thresholds = ThresholdParser::parse($config, RuleOptionSurface::bandFor(self::class, 'threshold'), 0.8, 0.95);
        return new self(
            enabled: $config->boolean('enabled', true),
            maxWarning: $thresholds['warning'],
            maxError: $thresholds['error'],
            minClassCount: $config->integer('min-class-count', 3),
            minAfferent: $config->integer('min-afferent', 1),
        );
    }

    /**
     * Keep `threshold` here even though no constructor parameter names it:
     * `ThresholdParser::parse()` reads that key through its default
     * `$thresholdKey`.
     */
    public static function acceptedOptionKeys(): RuleOptionKeySet
    {
        return RuleOptionKeySet::of([
            'enabled' => RuleOptionShape::boolean()->orNull(),
            'max-error' => RuleOptionShape::number()->orNull(),
            'max-warning' => RuleOptionShape::number()->orNull(),
            'min-afferent' => RuleOptionShape::integer()->orNull(),
            'min-class-count' => RuleOptionShape::integer()->orNull(),
            'threshold' => RuleOptionShape::number()->orNull(),
        ])->band('threshold', 'max-warning', 'max-error', BandDirection::Rising);
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function getSeverity(int|float $value): ?Severity
    {
        $instability = (float) $value;

        if ($instability >= $this->maxError) {
            return Severity::Error;
        }

        if ($instability >= $this->maxWarning) {
            return Severity::Warning;
        }

        return null;
    }

    public function withOverride(int|float|null $warning, int|float|null $error): static
    {
        return new static(
            enabled: $this->enabled,
            maxWarning: $warning !== null ? (float) $warning : $this->maxWarning,
            maxError: $error !== null ? (float) $error : $this->maxError,
            minClassCount: $this->minClassCount,
            minAfferent: $this->minAfferent,
        );
    }

    public function warningBoundary(): float
    {
        return $this->maxWarning;
    }
}
