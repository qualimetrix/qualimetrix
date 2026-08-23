<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Inline\Directive;

use Qualimetrix\Analysis\Evidence\Measurement\Contract\SymbolLevel;
use Qualimetrix\Analysis\Finding\Contract\ChannelDeclaration;
use Qualimetrix\Analysis\Finding\Contract\ChannelIdentityInterface;
use Qualimetrix\Analysis\Finding\Contract\ConfigurationValidatorInterface;
use Qualimetrix\Analysis\Finding\Contract\Location;
use Qualimetrix\Analysis\Finding\Contract\Rule\AnalysisContext;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionsInterface;
use Qualimetrix\Analysis\Finding\Contract\Severity;
use Qualimetrix\Analysis\Finding\Contract\Threshold\ThresholdOverride;
use Qualimetrix\Analysis\Finding\Contract\Violation;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\MetricSubject;
use Qualimetrix\Core\Symbol\SymbolPath;

/**
 * Reports the inline directives a run carried that can never do what they say.
 *
 * A directive whose name addresses nothing it is allowed to address is a
 * configuration mistake, and a mistake stays a mistake whether it is a typo, a
 * rule name written where a channel was meant, or a rule that simply cannot be
 * retuned. That is why these three channels belong to a validator and not to
 * {@see InlineDirectiveRule}: the classification is the producer's type.
 *
 * What is deliberately *not* reported here is a directive that addressed
 * something real and merely did not fire. That is what ordinary debt cleanup
 * looks like, it stays with the rule on `annotation.unused-directive`, and
 * conflating the two would fail every project that fixed a violation and left
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
     * that the emission guard can read them at each `new Violation(...)`
     * site: it resolves `self::CONST`, not a string handed in as a parameter.
     * The values still come from the owning contract.
     */
    private const string UNRESOLVED_CHANNEL = InlineDirectivePolicy::UNRESOLVED_DIRECTIVE_NAME;

    private const string UNSUPPORTED_CHANNEL = InlineDirectivePolicy::UNSUPPORTED_THRESHOLD_NAME;

    private const string INVALID_CHANNEL = InlineDirectivePolicy::INVALID_THRESHOLD_NAME;

    /**
     * Built here rather than injected: it is a pure function of the same
     * universe, and it has no lifecycle of its own.
     */
    private readonly DirectiveAddressability $addressability;

    /**
     * The options are the producer rule's own, the same configuration
     * `--rule-opt=annotation.directive:enabled=false` writes. A validator has
     * no thresholds of its own, but it is switched off with its producer.
     */
    public function __construct(
        private readonly RuleOptionsInterface $options,
        private readonly InlineDirectivePolicy $policy,
        ChannelIdentityInterface $identity,
    ) {
        $this->addressability = new DirectiveAddressability($identity);
    }

    public static function producerRuleName(): string
    {
        return InlineDirectivePolicy::PRODUCER_RULE_NAME;
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
            self::key(self::UNRESOLVED_CHANNEL) => ChannelDeclaration::occurrence(SymbolLevel::File),
            self::key(self::UNSUPPORTED_CHANNEL) => ChannelDeclaration::occurrence(SymbolLevel::File),
            self::key(self::INVALID_CHANNEL) => ChannelDeclaration::occurrence(SymbolLevel::File),
        ];
    }

    /**
     * Each of these channels carries its own name in both halves of the key,
     * so the key is that name twice — spelled out rather than built through a
     * value object, because a declaration read by reflection should need
     * nothing but strings.
     */
    private static function key(string $channelName): string
    {
        return $channelName . '#' . $channelName;
    }

    /**
     * @return list<Violation>
     */
    public function validate(AnalysisContext $context): array
    {
        if (!$this->options->isEnabled()) {
            return [];
        }

        return [
            ...$this->suppressionFindings(),
            ...$this->thresholdFindings(),
            ...$this->invalidThresholdFindings(),
        ];
    }

    /** @return list<Violation> */
    private function suppressionFindings(): array
    {
        $findings = [];

        foreach ($this->policy->authoredSuppressions() as $file => $suppressions) {
            $path = RelativePath::fromString($file);

            foreach ($suppressions as $suppression) {
                $problem = $this->addressability->problemWithSuppression($suppression);
                if ($problem === null) {
                    continue;
                }

                $findings[] = self::unresolved($path, $suppression->line, $problem);
            }
        }

        return $findings;
    }

    /** @return list<Violation> */
    private function thresholdFindings(): array
    {
        $findings = [];

        foreach ($this->policy->authoredThresholdOverrides() as $file => $overrides) {
            $path = RelativePath::fromString($file);

            foreach ($overrides as $override) {
                $findings[] = $this->thresholdFinding($path, $override);
            }
        }

        return array_values(array_filter($findings));
    }

    private function thresholdFinding(RelativePath $path, ThresholdOverride $override): ?Violation
    {
        $rejection = $this->addressability->problemWithThreshold($override);
        if ($rejection === null) {
            return null;
        }

        if (!$rejection->ruleExistsButCannotBeRetuned) {
            return self::unresolved($path, $override->line, $rejection->message);
        }

        $subject = self::authoringSubject($path);

        return new Violation(
            location: new Location($path, $override->line, precise: true),
            subject: $subject,
            symbolPath: $subject->toSymbolPath(),
            ruleName: self::UNSUPPORTED_CHANNEL,
            violationCode: self::UNSUPPORTED_CHANNEL,
            message: $rejection->message,
            severity: Severity::Error,
        );
    }

    /**
     * Malformed values are the same class of mistake as an unaddressable
     * name, and they arrive already diagnosed by the extractor.
     *
     * The validator's stable code used to be spliced into the violation code,
     * which made every new validator outcome a new channel nobody declared.
     * It is data about this finding, so it is reported as data.
     *
     * @return list<Violation>
     */
    private function invalidThresholdFindings(): array
    {
        $findings = [];

        foreach ($this->policy->authoredThresholdDiagnostics() as $file => $diagnostics) {
            $path = RelativePath::fromString($file);

            foreach ($diagnostics as $diagnostic) {
                $subject = self::authoringSubject($path);
                $findings[] = new Violation(
                    location: new Location($path, $diagnostic->line, precise: true),
                    subject: $subject,
                    symbolPath: $subject->toSymbolPath(),
                    ruleName: self::INVALID_CHANNEL,
                    violationCode: self::INVALID_CHANNEL,
                    message: $this->addressability->describeDiagnostic($diagnostic),
                    severity: Severity::Error,
                    recommendation: $diagnostic->hint,
                );
            }
        }

        return $findings;
    }

    /**
     * Where a directive finding belongs: the file the annotation is written
     * in, never one of the declarations the annotation was bound to.
     *
     * The extraction layer binds a class docblock to the class and to every
     * method in it, so "the subject" of a directive would otherwise be
     * whichever binding happened to come first — `Demo\Big::a` for an
     * annotation written on `Demo\Big`. The `Location` carries the exact
     * line, which is the only placement the author can act on.
     */
    private static function authoringSubject(RelativePath $path): MetricSubject
    {
        return MetricSubject::aggregate(SymbolPath::forFile($path));
    }

    /**
     * The only channel with two authoring surfaces behind it: a suppression
     * and a threshold directive can both name something unaddressable, and
     * both are the same mistake.
     */
    private static function unresolved(
        RelativePath $path,
        int $line,
        string $message,
    ): Violation {
        $subject = self::authoringSubject($path);

        return new Violation(
            location: new Location($path, $line, precise: true),
            subject: $subject,
            symbolPath: $subject->toSymbolPath(),
            ruleName: self::UNRESOLVED_CHANNEL,
            violationCode: self::UNRESOLVED_CHANNEL,
            message: $message,
            severity: Severity::Error,
        );
    }
}
