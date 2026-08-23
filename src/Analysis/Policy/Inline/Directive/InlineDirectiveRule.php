<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Inline\Directive;

use Qualimetrix\Analysis\Evidence\Measurement\Contract\SymbolLevel;
use Qualimetrix\Analysis\Finding\Contract\ChannelDeclaration;
use Qualimetrix\Analysis\Finding\Contract\ChannelIdentityInterface;
use Qualimetrix\Analysis\Finding\Contract\Location;
use Qualimetrix\Analysis\Finding\Contract\Rule\AbstractRule;
use Qualimetrix\Analysis\Finding\Contract\Rule\AnalysisContext;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleCategory;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionsInterface;
use Qualimetrix\Analysis\Finding\Contract\Severity;
use Qualimetrix\Analysis\Finding\Contract\Threshold\ThresholdOverride;
use Qualimetrix\Analysis\Finding\Contract\Violation;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\MetricSubject;
use Qualimetrix\Core\Symbol\SymbolPath;

/**
 * Reports what is wrong with the inline directives a run carried.
 *
 * The three channels emitted here are the loud half of the answer: a
 * directive whose name addresses nothing it is allowed to address is a
 * configuration mistake, and a mistake stays a mistake whether it is a typo,
 * a rule name written where a channel was meant, or a rule that simply cannot
 * be retuned. All three are declared
 * {@see \Qualimetrix\Analysis\Finding\Contract\ChannelAcceptability::ConfigurationError},
 * so none of them can be accepted as debt and each ends the run with a
 * non-zero code regardless of `fail_on`.
 *
 * What is deliberately *not* reported here is a directive that addressed
 * something real and merely did not fire. That is what ordinary debt cleanup
 * looks like, it is reported on a separate channel below `Error`, and
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
 * The four channels carry rule names of their own; nothing is ever emitted
 * under {@see InlineDirectivePolicy::PRODUCER_RULE_NAME}, which
 * exists so that the family has one owner to disable, configure and declare
 * against.
 */
final class InlineDirectiveRule extends AbstractRule
{
    public const string NAME = InlineDirectivePolicy::PRODUCER_RULE_NAME;
    public const string DOCS_PAGE = 'rules/annotation.md';

    public const int REMEDIATION_MINUTES = 15;
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
     * universe, it has no lifecycle of its own, and rules take only what the
     * options compiler pass can bind.
     */
    private readonly DirectiveAddressability $addressability;

    public function __construct(
        RuleOptionsInterface $options,
        private readonly InlineDirectivePolicy $policy,
        ChannelIdentityInterface $identity,
    ) {
        parent::__construct($options);
        $this->addressability = new DirectiveAddressability($identity);
    }

    public function getName(): string
    {
        return self::NAME;
    }

    public function getDescription(): string
    {
        return 'Reports inline @qmx directives that address nothing, cannot apply, or no longer do anything.';
    }

    public function getCategory(): RuleCategory
    {
        return RuleCategory::Annotation;
    }

    /** @return list<string> */
    public function requires(): array
    {
        return [];
    }

    /**
     * @return class-string<InlineDirectiveOptions>
     */
    public static function getOptionsClass(): string
    {
        return InlineDirectiveOptions::class;
    }

    /**
     * A threshold override is a statement about this rule's own options, and
     * this rule has no numeric threshold to retune.
     */
    public const bool SUPPORTS_THRESHOLD_OVERRIDE = false;

    /**
     * All four channels report on the configuration rather than on a measured
     * quantity, so every one of them is an `occurrence`.
     *
     * Three are configuration errors and one is not, and the split is the
     * whole point of {@see \Qualimetrix\Analysis\Finding\Contract\ChannelAcceptability}:
     * a directive that can never work has to be fixed, while a directive that
     * merely stopped being needed is cleanup a project may schedule.
     *
     * @return array<string, ChannelDeclaration>
     */
    public static function channelDeclarations(): array
    {
        return [
            self::key(InlineDirectivePolicy::UNRESOLVED_DIRECTIVE_NAME)
                => ChannelDeclaration::configurationError(SymbolLevel::File),
            self::key(InlineDirectivePolicy::UNSUPPORTED_THRESHOLD_NAME)
                => ChannelDeclaration::configurationError(SymbolLevel::File),
            self::key(InlineDirectivePolicy::INVALID_THRESHOLD_NAME)
                => ChannelDeclaration::configurationError(SymbolLevel::File),
            self::key(InlineDirectivePolicy::UNUSED_DIRECTIVE_NAME)
                => ChannelDeclaration::occurrence(SymbolLevel::File),
        ];
    }

    /**
     * Each of these four channels carries its own name in both halves of the
     * key, so the key is that name twice — spelled out rather than built
     * through a value object, because a declaration read by reflection should
     * need nothing but strings.
     */
    private static function key(string $channelName): string
    {
        return $channelName . '#' . $channelName;
    }

    /**
     * @return list<Violation>
     */
    public function analyze(AnalysisContext $context): array
    {
        \assert($this->options instanceof InlineDirectiveOptions);

        if (!$this->options->isEnabled()) {
            return [];
        }

        $this->policy->enableUsageReporting($this->options->unusedDirectiveSeverity);

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
