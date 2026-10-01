<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Architecture\LayerViolation;

use Qualimetrix\Analysis\Finding\Contract\Rule\ResolvedRuleOptionValues;

use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionKeySet;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionShape;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionsInterface;
use Qualimetrix\Analysis\Finding\Contract\Severity;

/**
 * Options for {@see UnassignedClassRule}: one key, and it is the gate.
 *
 * `mode` decides both whether the channel reports and how loudly, so
 * {@see isEnabled()} is derived from it rather than sitting beside it. A
 * second `enabled` key would be a second switch for one decision — and the
 * one that is off by default would silently win over the one the author
 * wrote.
 *
 * It is also the only gate in fact and not only in intent, which took one fix
 * after the split: the shared walk in
 * {@see \Qualimetrix\Analysis\Policy\Architecture\LayerViolation\Observation\LayerEvidenceCollector} read the
 * layer-violation rule's `enabled` as its entry condition, so
 * `layer-violation: {enabled: false}` silenced this channel from a sibling's
 * options. The walk now runs for either producer and every consumer checks its
 * own gate. `--disable-rule=architecture.layer-violation` never silenced this
 * rule — that is the selector, and it addresses the two producers separately.
 */
final readonly class UnassignedClassOptions implements RuleOptionsInterface
{
    /**
     * Duplicates {@see UnassignedClassRule::NAME} as a literal rather than
     * referencing the class constant, so this Options DTO does not gain a
     * dependency edge onto the rule it configures — the same reason
     * {@see LayerViolationOptions} spells its own rule name out.
     */

    public function __construct(
        public UnassignedClassMode $mode = UnassignedClassMode::Ignore,
    ) {}

    public static function fromResolved(ResolvedRuleOptionValues $config): self
    {
        return new self(
            mode: UnassignedClassMode::from(strtolower($config->text('mode', 'ignore'))),
        );
    }

    public static function acceptedOptionKeys(): RuleOptionKeySet
    {
        return RuleOptionKeySet::of([
            'mode' => RuleOptionShape::oneOfIgnoringCase('ignore', 'warn', 'error')->orNull(),
        ]);
    }

    public function isEnabled(): bool
    {
        return $this->mode !== UnassignedClassMode::Ignore;
    }

    /**
     * The mode read as a severity, or null while the gate is off — the same
     * "disabled and within tolerance answer alike" contract every other rule's
     * options keep.
     */
    public function getSeverity(int|float $value): ?Severity
    {
        return match ($this->mode) {
            UnassignedClassMode::Ignore => null,
            UnassignedClassMode::Warn => Severity::Warning,
            UnassignedClassMode::Error => Severity::Error,
        };
    }

}
