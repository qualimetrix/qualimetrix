<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Inline\Directive;

use Qualimetrix\Analysis\Finding\Contract\Rule\ResolvedRuleOptionValues;

use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionKeySet;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionShape;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionsInterface;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionWordSet;
use Qualimetrix\Analysis\Finding\Contract\Severity;

/**
 * Options for {@see UnusedDirectiveRule}.
 *
 * There is deliberately no severity key for the three configuration-error
 * channels. Their acceptability makes them gate unconditionally, past
 * `fail_on`, so a severity key there would look like a behaviour switch while
 * changing nothing but a word in the report — the same lie as a directive
 * that does nothing.
 *
 * The one severity that is a real choice is the unused-directive channel's:
 * leftover suppressions are ordinary cleanup, and a project mid-cleanup may
 * legitimately want them louder or quieter. It defaults to `Warning`, while
 * an explicit `Info` keeps the quieter adoption path available.
 */
final readonly class InlineDirectiveOptions implements RuleOptionsInterface
{
    public function __construct(
        public bool $enabled = true,
        public Severity $unusedDirectiveSeverity = Severity::Warning,
    ) {}

    public static function fromResolved(ResolvedRuleOptionValues $config): self
    {
        return new self(
            enabled: $config->boolean('enabled', true),
            unusedDirectiveSeverity: Severity::from(strtolower($config->text('unused-directive-severity', Severity::Warning->value))),
        );
    }

    public static function acceptedOptionKeys(): RuleOptionKeySet
    {
        return RuleOptionKeySet::of([
            'unused-directive-severity' => RuleOptionShape::words(RuleOptionWordSet::foldingCase('info', 'warning', 'error'))->orNull(),
        ]);
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Every channel this rule emits carries the severity its own emission
     * site decided: `Error` for the three configuration errors, because
     * acceptability already makes them unconditional, and the configured
     * value for the unused-directive channel. There is no metric value to
     * grade, so the answer does not depend on the argument.
     */
    public function getSeverity(int|float $value): Severity
    {
        return Severity::Error;
    }

}
