<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Inline\Directive;

use Qualimetrix\Analysis\Finding\Contract\ChannelDeclaration;
use Qualimetrix\Analysis\Finding\Contract\ChannelSelectionRole;
use Qualimetrix\Analysis\Finding\Contract\ChannelShape;
use Qualimetrix\Analysis\Finding\Contract\ConfigurationValidatorInterface;
use Qualimetrix\Analysis\Finding\Contract\Finding;
use Qualimetrix\Analysis\Finding\Contract\Location;
use Qualimetrix\Analysis\Finding\Contract\Rule\AnalysisContext;
use Qualimetrix\Analysis\Finding\Contract\Severity;
use Qualimetrix\Core\Symbol\MetricSubject;
use Qualimetrix\Core\Symbol\SymbolLevel;
use Qualimetrix\Core\Symbol\SymbolPath;

/**
 * Reports the inline directives a run carried that can never do what they say.
 *
 * A directive whose name addresses nothing it is allowed to address is a
 * configuration mistake, and a mistake stays a mistake whether it is a typo, a
 * rule name written where a channel was meant, or a rule that simply cannot be
 * retuned. That is why these three channels belong to a validator and not to
 * {@see UnusedDirectiveRule}: the classification is the producer's type.
 *
 * What is deliberately *not* reported here is a directive that addressed
 * something real and merely did not fire. That is what ordinary debt cleanup
 * looks like, it stays with the rule on `annotation.unused-directive`, and
 * conflating the two would fail every project that fixed a finding and left
 * its annotation behind.
 *
 * **The check runs after configuration has resolved, and that is load-bearing.**
 * The universe it consults is built from the run's own configuration, so a
 * channel that exists only because the user defined a computed metric — a
 * live `health.*` name — resolves exactly like a statically declared one. A
 * check written against the static declarations alone would call every such
 * annotation a mistake.
 *
 * The three channels carry rule names of their own; nothing is ever emitted
 * under {@see InlineDirectivePolicy::PRODUCER_RULE_NAME}, which exists so that
 * the family has one owner to disable, configure and declare against — and
 * which is also this validator's producer.
 */
final class InlineDirectiveValidator implements ConfigurationValidatorInterface
{
    /**
     * The three channel names, restated here as `self::` constants purely so
     * that the emission guard can read them at each `new Finding(...)`
     * site: it resolves `self::CONST`, not a string handed in as a parameter.
     * The values still come from the owning contract.
     */
    private const string UNRESOLVED_CHANNEL = InlineDirectivePolicy::UNRESOLVED_DIRECTIVE_NAME;

    private const string UNSUPPORTED_CHANNEL = InlineDirectivePolicy::UNSUPPORTED_THRESHOLD_NAME;

    private const string INVALID_CHANNEL = InlineDirectivePolicy::INVALID_THRESHOLD_NAME;

    public function __construct(
        private readonly InlineDirectivePolicy $policy,
        private readonly RefusedDirectives $refused,
    ) {}

    public static function producerRuleName(): string
    {
        return InlineDirectivePolicy::PRODUCER_RULE_NAME;
    }

    /**
     * Shared with {@see UnusedDirectiveRule}, the rule this validator belongs
     * to: registry assembly refuses the two declaring different shapes under
     * one producer name.
     */
    public static function shape(): ChannelShape
    {
        return ChannelShape::Occurrence;
    }

    /**
     * All three report on the configuration rather than on a measured
     * quantity, so every one of them is an `occurrence` at the level of the
     * file the annotation is written in.
     *
     * @return array<string, ChannelDeclaration>
     */
    public static function channelDeclarations(): array
    {
        return [
            self::UNRESOLVED_CHANNEL => ChannelDeclaration::occurrence(SymbolLevel::File)
                ->describedAs('Reports an inline directive that addresses nothing it may address, or that is malformed before any channel is read.'),
            self::UNSUPPORTED_CHANNEL => ChannelDeclaration::occurrence(SymbolLevel::File)
                ->selectedAs(ChannelSelectionRole::FollowsAddressedRule)
                ->describedAs('Reports a threshold directive that targets a rule with no threshold-override support.'),
            self::INVALID_CHANNEL => ChannelDeclaration::occurrence(SymbolLevel::File)
                ->selectedAs(ChannelSelectionRole::FollowsAddressedRule)
                ->describedAs('Reports a threshold directive whose payload does not fit the targeted rule\'s options.'),
        ];
    }

    /**
     * @return list<Finding>
     */
    public function validate(AnalysisContext $context): array
    {
        $findings = [];
        foreach ($this->refused->all(
            $this->policy->authoredSuppressions(),
            $this->policy->authoredThresholdOverrides(),
            $this->policy->authoredThresholdDiagnostics(),
        ) as $refusal) {
            $findings[] = match ($refusal->channel) {
                DirectiveRefusalChannel::Unresolved => self::unresolved($refusal),
                DirectiveRefusalChannel::Unsupported => self::unsupported($refusal),
                DirectiveRefusalChannel::Invalid => self::invalid($refusal),
            };
        }

        return $findings;
    }

    private static function unresolved(RefusedDirective $refusal): Finding
    {
        $subject = MetricSubject::aggregate(SymbolPath::forFile($refusal->site->file));

        return new Finding(
            location: new Location($refusal->site->file, $refusal->site->line, precise: true),
            subject: $subject,
            symbolPath: $subject->toSymbolPath(),
            ruleName: self::UNRESOLVED_CHANNEL,
            code: self::UNRESOLVED_CHANNEL,
            message: $refusal->message,
            severity: Severity::Error,
            recommendation: $refusal->hint,
            addressedProducer: $refusal->addressedProducer,
        );
    }

    private static function unsupported(RefusedDirective $refusal): Finding
    {
        $subject = MetricSubject::aggregate(SymbolPath::forFile($refusal->site->file));

        return new Finding(
            location: new Location($refusal->site->file, $refusal->site->line, precise: true),
            subject: $subject,
            symbolPath: $subject->toSymbolPath(),
            ruleName: self::UNSUPPORTED_CHANNEL,
            code: self::UNSUPPORTED_CHANNEL,
            message: $refusal->message,
            severity: Severity::Error,
            recommendation: $refusal->hint,
            addressedProducer: $refusal->addressedProducer,
        );
    }

    private static function invalid(RefusedDirective $refusal): Finding
    {
        $subject = MetricSubject::aggregate(SymbolPath::forFile($refusal->site->file));

        return new Finding(
            location: new Location($refusal->site->file, $refusal->site->line, precise: true),
            subject: $subject,
            symbolPath: $subject->toSymbolPath(),
            ruleName: self::INVALID_CHANNEL,
            code: self::INVALID_CHANNEL,
            message: $refusal->message,
            severity: Severity::Error,
            recommendation: $refusal->hint,
            addressedProducer: $refusal->addressedProducer,
        );
    }

}
