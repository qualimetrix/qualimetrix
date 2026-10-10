<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Architecture\LayerViolation;

use Qualimetrix\Analysis\Finding\Contract\Rule\ResolvedRuleOptionValues;

use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionKeySet;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionShape;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionsInterface;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionWordSet;
use Qualimetrix\Analysis\Finding\Contract\Severity;

/**
 * Options for forbidden-edge findings only. Declaration diagnostics and
 * unassigned-class findings each have independent producer options.
 *
 * Removed declaration severity keys retain refusal wording so an authored
 * setting cannot silently become inert. Coverage severity still belongs to
 * the architecture section's `coverage-gap: ignore|warn|error` setting.
 */
final readonly class LayerViolationOptions implements RuleOptionsInterface
{
    /**
     * Duplicates {@see LayerViolationRule::NAME} as a literal rather than
     * referencing the class constant, so this Options DTO does not gain a
     * dependency edge onto the rule it configures (options → rule would
     * invert the natural rule → options relationship the rest of the
     * codebase follows).
     */
    private const string RULE_NAME = 'architecture.layer-violation';

    /**
     * @param bool $enabled Whether the rule is enabled.
     * @param Severity $severity Severity assigned to every reported `architecture.layer-violation`.
     */
    public function __construct(
        public bool $enabled = true,
        public Severity $severity = Severity::Warning,
    ) {}

    public static function fromResolved(ResolvedRuleOptionValues $config): self
    {
        return new self(
            enabled: $config->boolean('enabled', true),
            severity: Severity::from(strtolower($config->text('severity', Severity::Warning->value))),
        );
    }

    /**
     * The three removed severity keys are retired with owner-declared wording,
     * not accepted as live options. The schema refuses them before construction
     * and names both the removal and its replacement. Treating them as
     * unknown keys would lose that migration advice; accepting them would
     * silently discard a setting for diagnostics that always fail the run.
     * The retired declaration keeps the removed configuration traceable.
     */
    public static function acceptedOptionKeys(): RuleOptionKeySet
    {
        return RuleOptionKeySet::of([
            'severity' => RuleOptionShape::words(RuleOptionWordSet::foldingCase('info', 'warning', 'error'))->orNull(),
        ])->retiring('empty-template-severity', self::retiredSeverity('empty_template_severity'))
            ->retiring('potential-shadow-severity', self::retiredSeverity('potential_shadow_severity'))
            ->retiring('unreachable-layer-severity', self::retiredSeverity('unreachable_layer_severity'));

    }

    private static function retiredSeverity(string $key): string
    {
        return \sprintf(
            'Option "%s" for rule "%s" no longer exists. The channel it configured reports a configuration'
            . ' error, which always fails the run regardless of "fail_on" and can never be accepted by a'
            . ' baseline, so its severity was not a behaviour setting. Remove the key; to decline the'
            . ' coverage diagnostic itself, set "coverage-gap: ignore" in the architecture section.',
            $key,
            self::RULE_NAME,
        );
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Returns the configured severity for the rule.
     *
     * The rule has no numeric threshold — every forbidden edge is reported
     * with the same severity. When the rule is disabled, returns null so the
     * caller can treat "disabled" and "value within tolerance" uniformly.
     */
    public function getSeverity(int|float $value): ?Severity
    {
        if (!$this->enabled) {
            return null;
        }

        return $this->severity;
    }

}
