<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Architecture\UnassignedClass;

use Qualimetrix\Analysis\Finding\Contract\Rule\ModeGatedOptionsInterface;
use Qualimetrix\Analysis\Finding\Contract\Rule\ResolvedRuleOptionValues;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionKeySet;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionShape;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionWordSet;
use Qualimetrix\Analysis\Finding\Contract\Severity;

/**
 * Options for {@see UnassignedClassRule}: mode controls activity and severity,
 * while the framework enabled switch participates in the common selection.
 *
 * It is also the only gate in fact and not only in intent, which took one fix
 * after the split: the shared walk in
 * {@see \Qualimetrix\Analysis\Policy\Architecture\Observation\LayerEvidenceCollector} read the
 * layer-violation rule's `enabled` as its entry condition, so
 * `layer-violation: {enabled: false}` silenced this channel from a sibling's
 * options. The walk now runs for either producer and every consumer checks its
 * own gate. `--disable-rule=architecture.layer-violation` never silenced this
 * rule — that is the selector, and it addresses the two producers separately.
 */
final readonly class UnassignedClassOptions implements ModeGatedOptionsInterface
{
    public function __construct(
        public UnassignedClassMode $mode = UnassignedClassMode::Ignore,
        public bool $enabled = true,
    ) {}

    public static function fromResolved(ResolvedRuleOptionValues $config): self
    {
        return new self(
            mode: UnassignedClassMode::from(strtolower($config->text('mode', 'ignore'))),
            enabled: $config->boolean('enabled', true),
        );
    }

    public static function acceptedOptionKeys(): RuleOptionKeySet
    {
        return RuleOptionKeySet::of([
            'mode' => RuleOptionShape::words(RuleOptionWordSet::foldingCase('ignore', 'warn', 'error'))->orNull(),
        ]);
    }

    public function isEnabled(): bool
    {
        return $this->enabled && !$this->isMuted();
    }

    public function isMuted(): bool
    {
        return $this->mode === UnassignedClassMode::Ignore;
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
