<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Design\GodClass;

use Qualimetrix\Analysis\Finding\Contract\Rule\Override\OverrideValidatorInterface;
use Qualimetrix\Analysis\Finding\Contract\Rule\Override\WarningOnlyValidator;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionKey;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionKeySet;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionShape;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionsInterface;
use Qualimetrix\Analysis\Finding\Contract\Rule\ThresholdAwareOptionsInterface;
use Qualimetrix\Analysis\Finding\Contract\Severity;

/**
 * Options for GodClassRule.
 *
 * God Class detection uses Lanza & Marinescu criteria:
 * - WMC >= 47 (high complexity)
 * - LCOM4 >= 3 (low cohesion)
 * - TCC < 0.33 (low tight class cohesion, inverted)
 * - classLoc >= 300 (large size)
 *
 * A class is a God Class when it matches minCriteria out of evaluable criteria.
 *
 * `@qmx-threshold design.god-class W` maps W to `minCriteria` (the gate
 * deciding how many matched criteria are required to flag the class). The
 * `error` value has no separate threshold in this rule — Error severity is
 * emitted when all evaluable criteria match — so the `error` parameter of
 * the annotation is ignored. Use the `wmc_threshold`, `lcom_threshold`,
 * `tcc_threshold`, `class_loc_threshold` config keys to tune per-criterion
 * thresholds.
 *
 * @qmx-threshold coupling.instability warning=0.81 -- The eighth efferent edge is
 * `RuleOptionShape`, required so an options class can declare the value form of each key it
 * accepts. Ca=2, Ce=8 puts this at exactly 0.800 against an inclusive 0.800 ceiling, so
 * it is reported for reaching the limit rather than passing it. A rule options class is efferent
 * by construction: it names the option vocabulary it accepts and almost nothing names it back. The
 * sibling options classes that carry the same shape with a single afferent edge compute higher
 * still -- Ca=1 with this Ce is 0.889 -- and are not judged at all, because `min_afferent: 2`
 * filters them out; this class is judged only because one extra consumer names it, which makes the
 * ranking the wrong way round and is the metric mis-modelling the shape rather than a defect to
 * refactor. 0.81 silences today's 0.800 and still reports the next efferent edge, which takes Ce
 * to 9 and instability to 0.818.
 */
final readonly class GodClassOptions implements RuleOptionsInterface, ThresholdAwareOptionsInterface
{
    public function __construct(
        public bool $enabled = true,
        public int $wmcThreshold = 47,
        public int $lcomThreshold = 3,
        public float $tccThreshold = 0.33,
        public int $classLocThreshold = 300,
        public int $minCriteria = 3,
        public int $minMethods = 3,
        public bool $excludeReadonly = true,
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
            wmcThreshold: (int) ($config['wmc_threshold'] ?? $config['wmcThreshold'] ?? 47),
            lcomThreshold: (int) ($config['lcom_threshold'] ?? $config['lcomThreshold'] ?? 3),
            tccThreshold: (float) ($config['tcc_threshold'] ?? $config['tccThreshold'] ?? 0.33),
            classLocThreshold: (int) ($config['class_loc_threshold'] ?? $config['classLocThreshold'] ?? 300),
            minCriteria: (int) ($config['min_criteria'] ?? $config['minCriteria'] ?? 3),
            minMethods: (int) ($config['min_methods'] ?? $config['minMethods'] ?? 3),
            excludeReadonly: (bool) ($config['exclude_readonly'] ?? $config['excludeReadonly'] ?? true),
        );
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Severity is determined by the rule's analyze() logic, not here.
     * This method satisfies the interface contract.
     */
    public function getSeverity(int|float $value): ?Severity
    {
        // Severity is determined inline in analyze() based on matchedCount vs evaluableCount
        return $value > 0 ? Severity::Warning : null;
    }

    /**
     * Maps `@qmx-threshold design.god-class W` to `minCriteria` override.
     * The `error` parameter is ignored — this rule has no separate error threshold.
     */
    public function withOverride(int|float|null $warning, int|float|null $error): static
    {
        return new static(
            enabled: $this->enabled,
            wmcThreshold: $this->wmcThreshold,
            lcomThreshold: $this->lcomThreshold,
            tccThreshold: $this->tccThreshold,
            classLocThreshold: $this->classLocThreshold,
            minCriteria: $warning !== null ? (int) $warning : $this->minCriteria,
            minMethods: $this->minMethods,
            excludeReadonly: $this->excludeReadonly,
        );
    }

    public static function getOverrideValidator(): OverrideValidatorInterface
    {
        return WarningOnlyValidator::instance();
    }

    /**
     * `design.god-class` reports `$matchedCount`, and `GodClassRule` warns from
     * `matchedCount >= minCriteria` upwards. The decision is made inside the
     * rule rather than through {@see self::getSeverity()}, which stays a stub —
     * so this member, not that method, is what the channel is judged against.
     */
    public function warningBoundary(): int
    {
        return $this->minCriteria;
    }

    public static function acceptedOptionKeys(): RuleOptionKeySet
    {
        return RuleOptionKeySet::of([
            'class-loc-threshold' => RuleOptionShape::integer()->orNull(),
            'enabled' => RuleOptionShape::boolean()->orNull(),
            'exclude-readonly' => RuleOptionShape::boolean()->orNull(),
            'lcom-threshold' => RuleOptionShape::integer()->orNull(),
            'min-criteria' => RuleOptionShape::integer()->orNull(),
            'min-methods' => RuleOptionShape::integer()->orNull(),
            'tcc-threshold' => RuleOptionShape::number()->orNull(),
            'wmc-threshold' => RuleOptionShape::integer()->orNull(),
        ]);
    }
}
