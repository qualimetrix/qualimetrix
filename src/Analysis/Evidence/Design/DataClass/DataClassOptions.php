<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Design\DataClass;

use Qualimetrix\Analysis\Finding\Contract\Rule\Override\IndependentAxisValidator;
use Qualimetrix\Analysis\Finding\Contract\Rule\Override\OverrideValidatorInterface;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionKey;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionKeySet;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionShape;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionsInterface;
use Qualimetrix\Analysis\Finding\Contract\Rule\ThresholdAwareOptionsInterface;
use Qualimetrix\Analysis\Finding\Contract\Severity;

/**
 * Options for DataClassRule.
 *
 * A Data Class exposes state rather than behaviour: a low share of functional
 * public methods (WOC) combined with low complexity (WMC). The 33% default
 * follows the 1/3 cut-off of Lanza & Marinescu.
 *
 * `@qmx-threshold design.data-class W E` maps W to `wocThreshold`
 * (maximum WOC to flag) and E to `wmcThreshold` (maximum WMC to flag).
 * Both conditions must hold for the rule to flag a class.
 *
 * @qmx-threshold coupling.instability warning=0.81 -- The eighth efferent edge is
 * `RuleOptionShape`, the option-shape vocabulary X18 introduces so an options class can
 * declare the value form of each key it accepts; the counterfactual was measured, not assumed --
 * the import list against 72f18239 differs by exactly that one line, and without it Ce is 7 and
 * instability 0.778. Ca=2, Ce=8 puts this at exactly 0.800 against an inclusive 0.800 ceiling, so
 * it is reported for reaching the limit rather than passing it. A rule options class is efferent
 * by construction: it names the option vocabulary it accepts and almost nothing names it back. The
 * sibling options classes that carry the same shape with a single afferent edge compute higher
 * still -- Ca=1 with this Ce is 0.889 -- and are not judged at all, because `min_afferent: 2`
 * filters them out; this class is judged only because one extra consumer names it, which makes the
 * ranking the wrong way round and is the metric mis-modelling the shape rather than a defect to
 * refactor. 0.81 silences today's 0.800 and still reports the next efferent edge, which takes Ce
 * to 9 and instability to 0.818.
 */
final readonly class DataClassOptions implements RuleOptionsInterface, ThresholdAwareOptionsInterface
{
    public function __construct(
        public bool $enabled = true,
        public int $wocThreshold = 33,
        public int $wmcThreshold = 10,
        public int $minMembers = 3,
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
            wocThreshold: (int) ($config['woc_threshold'] ?? $config['wocThreshold'] ?? 33),
            wmcThreshold: (int) ($config['wmc_threshold'] ?? $config['wmcThreshold'] ?? 10),
            minMembers: (int) ($config['min_members'] ?? $config['minMembers'] ?? 3),
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
     * Both axes are upper bounds, so W below E is not an ordering error.
     */
    public function withOverride(int|float|null $warning, int|float|null $error): static
    {
        return new static(
            enabled: $this->enabled,
            wocThreshold: $warning !== null ? (int) $warning : $this->wocThreshold,
            wmcThreshold: $error !== null ? (int) $error : $this->wmcThreshold,
            minMembers: $this->minMembers,
            excludeReadonly: $this->excludeReadonly,
            excludePromotedOnly: $this->excludePromotedOnly,
            excludeExceptions: $this->excludeExceptions,
        );
    }

    public static function getOverrideValidator(): OverrideValidatorInterface
    {
        return IndependentAxisValidator::instance();
    }

    /**
     * `design.data-class` reports WOC and worsens downwards: `DataClassRule`
     * emits while `woc <= wocThreshold`, so this member is the boundary on the
     * reported axis. `wmcThreshold` gates a second metric and does not compete
     * for the answer, exactly as `minAfferent` and `minStatements` gate
     * elsewhere without stopping their classes from naming a boundary.
     *
     * The decision runs inside the rule; {@see self::getSeverity()} is a stub
     * and cannot witness this number.
     */
    public function warningBoundary(): int
    {
        return $this->wocThreshold;
    }

    public static function acceptedOptionKeys(): RuleOptionKeySet
    {
        return RuleOptionKeySet::of([
            'enabled' => RuleOptionShape::boolean()->orNull(),
            'exclude-exceptions' => RuleOptionShape::boolean()->orNull(),
            'exclude-promoted-only' => RuleOptionShape::boolean()->orNull(),
            'exclude-readonly' => RuleOptionShape::boolean()->orNull(),
            'min-members' => RuleOptionShape::integer()->orNull(),
            'wmc-threshold' => RuleOptionShape::integer()->orNull(),
            'woc-threshold' => RuleOptionShape::integer()->orNull(),
        ]);
    }
}
