<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Inline\Directive;

use Qualimetrix\Analysis\Finding\Contract\ChannelDeclarationRegistryInterface;
use Qualimetrix\Analysis\Finding\Contract\ChannelIdentityInterface;
use Qualimetrix\Analysis\Finding\Contract\Finding;
use Qualimetrix\Analysis\Finding\Contract\LevelActivity;
use Qualimetrix\Analysis\Finding\Contract\Rule\ChannelLevelAddressing;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleSelector;
use Qualimetrix\Analysis\Finding\Contract\RuleConfigurationInterface;
use Qualimetrix\Analysis\Finding\Contract\Severity;
use Qualimetrix\Analysis\Policy\Inline\Contract\Directive\DirectiveEffect;
use Qualimetrix\Analysis\Policy\Inline\Contract\Directive\DirectiveSite;
use Qualimetrix\Analysis\Policy\Inline\Contract\Directive\DirectiveUnmeasurableReason;
use Qualimetrix\Analysis\Policy\Inline\Contract\Directive\DirectiveVerdict;
use Qualimetrix\Analysis\Policy\Inline\Contract\Directive\InlineDirectivePolicyInterface;
use Qualimetrix\Analysis\Policy\Inline\Contract\Suppression\Suppression;
use Qualimetrix\Analysis\Policy\Inline\Suppression\SuppressionFilter;
use Qualimetrix\Core\Path\RelativePath;

/**
 * The post-execution half of the inline-directive subject: what each authored
 * suppression did this run.
 *
 * The answer is a {@see DirectiveVerdict} per authored site, and the stale
 * findings {@see stale()} returns are one projection of it. Two computations
 * would be two chances to disagree about one directive, which is why the
 * projection reads the verdicts rather than repeating the accounting.
 *
 * **One computation, but not always one universe, and the difference is
 * visible.** `annotation.unused-directive` is this class's own output, so
 * `stale()` cannot be given a finding list containing it — a caller asking for
 * the channel is asking for that list to be produced. A caller asking for
 * verdicts is not, and is given the wider list, the channel included. A
 * suppression aimed at that channel is therefore answered differently by the
 * two: a `@qmx-ignore-next-line annotation.unused-directive` above a stale
 * directive is reported live by the verdicts and stale by the channel. The
 * verdicts are the correct answer of the two — it did silence the neighbour's
 * finding — and the channel's blind spot predates them. Recorded rather than
 * papered over; narrowing the verdicts back to the channel's list would
 * restore the defect that made every such suppression inert by construction.
 *
 * The one narrowing that *is* correct is by directive rather than by channel,
 * and {@see withoutOwnComplaint()} does it: a directive may not be credited
 * with silencing the complaint it produced by being dead.
 *
 * It is a pure function of the prepared directives and the produced findings,
 * and it holds no run state — {@see InlineDirectivePolicy} keeps that and asks
 * this once, after every rule has finished. Splitting the two is what keeps
 * the run state a store: the accounting needs the channel universe, the rule
 * selection and the finding vocabulary, and none of those has anything to do
 * with holding directives for the length of a run.
 *
 * `verdicts()` is public with no production caller yet: the operation that
 * carries verdicts across the owner boundary lands with its consumer. That is a
 * method on an internal class, invisible to the manifest — unlike a contract
 * operation, which the checker refuses without a consumer that exists.
 */
final class DirectiveUsage
{
    /**
     * The shared refusal for a `channel:level` pair. Built here rather than
     * injected, mirroring {@see DirectiveAddressability}: a pure function of
     * the same universe, with no lifecycle of its own.
     */
    private readonly ChannelLevelAddressing $levels;

    /**
     * Two views and not the composite that implements both: the composite
     * exists so one object can answer everything, not so every consumer may
     * ask everything, and it says so itself. The composition root passes the
     * same instance to both, which is what makes the two answers one universe.
     */
    public function __construct(
        private readonly ChannelIdentityInterface $identity,
        private readonly RuleSelector $ruleSelector,
        private readonly RuleConfigurationInterface $ruleConfiguration,
        private readonly ChannelDeclarationRegistryInterface $declarations,
    ) {
        $this->levels = new ChannelLevelAddressing($identity);
    }

    /**
     * What each authored suppression did this run.
     *
     * The single computation behind both answers this class gives: `stale()`
     * is its projection into findings, and a report that lists directives
     * reads it directly. Two computations would be two chances to disagree
     * about the same directive.
     *
     * @param array<string, list<Suppression>> $suppressionsByFile file => directives, as prepared
     * @param list<Finding> $findings everything the rules produced this run
     *
     * @return list<DirectiveVerdict>
     */
    public function verdicts(array $suppressionsByFile, array $findings, LevelActivity $activity): array
    {
        return array_map(
            static fn(array $pair): DirectiveVerdict => $pair['verdict'],
            $this->evaluate($suppressionsByFile, $findings, $activity),
        );
    }

    /**
     * The suppressions that addressed something real and still matched
     * nothing this run.
     *
     * @param array<string, list<Suppression>> $suppressionsByFile file => directives, as prepared
     * @param list<Finding> $findings everything the rules produced this run
     *
     * @return list<Finding>
     */
    public function stale(
        array $suppressionsByFile,
        array $findings,
        Severity $severity,
        LevelActivity $activity,
    ): array {
        $stale = [];

        foreach ($this->evaluate($suppressionsByFile, $findings, $activity) as $pair) {
            if ($pair['verdict']->effect === DirectiveEffect::Inert) {
                $stale[] = StaleDirectiveFinding::of($pair['verdict']->site->file, $pair['directive'], $severity);
            }
        }

        return $stale;
    }

    /**
     * The findings this directive may be credited with, which are all of them
     * except the complaint about itself.
     *
     * A directive that silences nothing produces one: `stale()` writes an
     * `annotation.unused-directive` finding at the line the directive was
     * written on, and a file-scoped suppression of that very channel covers
     * every line of its file, its own included. Left in, the finding a
     * directive earns by being dead is the finding that proves it alive —
     * measured on a fixture whose only annotation is
     * `@qmx-ignore-file annotation.unused-directive`, which `check` shows
     * changes nothing and which came out `effective`.
     *
     * The exclusion is by line, so two directives authored on one line lose
     * each other's complaint as well as their own. That is a deliberate
     * approximation of an exact identity this class cannot read back off a
     * finding, and it errs where the alternative errs worse: such a directive
     * is still credited with every complaint elsewhere in its file, so the
     * approximation bites only when the whole file has no other.
     *
     * @param list<Finding> $findings
     *
     * @return list<Finding>
     */
    private static function withoutOwnComplaint(string $file, Suppression $directive, array $findings): array
    {
        return array_values(array_filter($findings, static fn(Finding $finding): bool
            => $finding->code !== InlineDirectivePolicyInterface::UNUSED_DIRECTIVE_NAME
            || $finding->location->line !== $directive->line
            || $finding->location->pathString() !== $file));
    }

    /**
     * The findings a suppression could have silenced at all.
     *
     * A channel a configuration validator declares is exempt from annotation
     * suppression by the kind of thing it is, not by anyone's configuration:
     * a misconfigured directive is not silenced by another directive, and the
     * projection enforces that for every report. Counting such a finding as
     * something a suppression matched would call a directive live that can
     * never do anything — measured on a fixture, `@qmx-ignore-file
     * annotation.unresolved-directive` reported "effective" while `check`
     * printed the error it claimed to silence.
     *
     * This is not the publication ledger of D4 creeping back in. That ledger
     * is a configuration choice about a report; this is a property of the
     * producing type, true for every run and every configuration.
     *
     * @param list<Finding> $findings
     *
     * @return list<Finding>
     */
    private function suppressible(array $findings): array
    {
        return array_values(array_filter(
            $findings,
            fn(Finding $finding): bool
                => $this->declarations->declarationFor($finding->channel())?->isConfigurationError() !== true,
        ));
    }

    /**
     * One verdict per authored site, paired with the directive it came from.
     *
     * The pair exists because the two projections need different halves: the
     * report reads the verdict, and the finding needs the directive to render
     * the target the author wrote.
     *
     * @param array<string, list<Suppression>> $suppressionsByFile
     * @param list<Finding> $findings
     *
     * @return list<array{verdict: DirectiveVerdict, directive: Suppression}>
     */
    private function evaluate(array $suppressionsByFile, array $findings, LevelActivity $activity): array
    {
        $selection = $this->ruleConfiguration->selection();
        $findings = $this->suppressible($findings);
        $evaluated = [];

        foreach ($suppressionsByFile as $file => $fileSuppressions) {
            foreach (self::groupByAuthoredSite($fileSuppressions) as $group) {
                $directive = $group[0];
                $reason = $this->unmeasurableReason($group, $activity, $selection->only, $selection->disabled);

                $effect = match (true) {
                    $reason !== null => DirectiveEffect::Unmeasured,
                    self::anyOfTheGroupFired($file, $group, self::withoutOwnComplaint($file, $directive, $findings))
                        => DirectiveEffect::Effective,
                    default => DirectiveEffect::Inert,
                };

                $evaluated[] = [
                    'verdict' => new DirectiveVerdict(
                        site: new DirectiveSite(
                            file: RelativePath::fromString($file),
                            line: $directive->line,
                            form: $directive->type->value,
                            target: (string) $directive->target(),
                        ),
                        effect: $effect,
                        reason: $reason,
                    ),
                    'directive' => $directive,
                ];
            }
        }

        return $evaluated;
    }

    /**
     * The same identity as {@see InlineDirectivePolicy::authoredSuppressions()},
     * but keeping every binding rather than one.
     *
     * The usage question genuinely needs them all: the author wrote one
     * directive, and it did something as soon as *any* declaration it covers
     * was silenced — which for a class-level directive is usually the class
     * and not the five methods beside it.
     *
     * @param list<Suppression> $suppressions
     *
     * @return list<non-empty-list<Suppression>>
     */
    private static function groupByAuthoredSite(array $suppressions): array
    {
        $groups = [];

        foreach ($suppressions as $suppression) {
            $groups[$suppression->line . "\0" . $suppression->type->value . "\0" . $suppression->rule][] = $suppression;
        }

        return array_values($groups);
    }

    /**
     * The group fired if any of its bindings did: the author wrote one
     * directive, and it did something as soon as one subject it covers was
     * silenced.
     *
     * @param list<Suppression> $group
     * @param list<Finding> $findings
     */
    private static function anyOfTheGroupFired(string $file, array $group, array $findings): bool
    {
        foreach ($group as $suppression) {
            if (SuppressionFilter::suppressesAny($file, $suppression, $findings)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Why this directive cannot be judged, or null when it can.
     *
     * The accounting is deliberately narrow, and every limit here is one that
     * would otherwise turn ordinary configuration into noise. It answers with
     * a named reason rather than a boolean because the caller reports the
     * difference: a directive nobody could ask about is not a directive that
     * did nothing.
     *
     * A directive is judged only when the rule producing the channel it
     * addresses actually reported: without that, disabling a family of rules
     * — as the shipped `legacy` preset does — would report every annotation
     * belonging to it as stale. **Both** ways of switching a rule off count,
     * because the user made the same decision either way: `disabled_rules`
     * and `--disable-rule` stop the rule from running, and `rules: { X:
     * false }` / `rules: { X: { enabled: false } }` let it run and return
     * nothing. Reading only the first is what made the second report every
     * annotation of the switched-off rule as a leftover. The other limit
     * needs no code: the directive maps are keyed by the files collection
     * actually analysed, so a run scoped to part of the tree never sees an
     * annotation outside it.
     *
     * A directive that carries no rule filter at all is never judged. It says
     * "whatever is here", so there is no channel whose producer could be
     * consulted, and reporting it stale would mean reporting the file's
     * cleanliness as a defect.
     *
     * Nor is one judged whose pair {@see ChannelLevelAddressing} already
     * refused, whose selector expands to no channel at all, or whose channels
     * no producer owns: `coupling.cbo:project`, naming a level
     * `coupling.cbo` never reports at, can never be silenced by any finding,
     * so calling it stale on top of the `annotation.unresolved-directive`
     * {@see DirectiveAddressability} already raised would answer one mistake
     * twice. All three arrive here as the same answer for the same reason.
     *
     * The levels come from the whole authored group rather than from its first
     * binding: one authored site expands to a binding per applicable
     * declaration, and those need not share a level — a class docblock covers
     * the class and every callable in it. Judging by the first alone reports a
     * directive that is alive at one of its levels as unmeasured, which is the
     * mirror image of the defect this reading exists to remove.
     *
     * @param non-empty-list<Suppression> $group
     * @param list<string> $only
     * @param list<string> $disabled
     */
    private function unmeasurableReason(
        array $group,
        LevelActivity $activity,
        array $only,
        array $disabled,
    ): ?DirectiveUnmeasurableReason {
        $suppression = $group[0];
        $target = $suppression->target();
        if ($target->appliesToEveryChannel()) {
            return DirectiveUnmeasurableReason::AddressesEveryChannel;
        }

        if ($this->levels->problemWith((string) $target) !== null) {
            return DirectiveUnmeasurableReason::AlreadyRefused;
        }

        $sawDisabledProducer = false;

        foreach ($this->addressedCodes($suppression) as $code) {
            $producer = $this->identity->producerOf($code);
            if ($producer === null) {
                // Unreachable through a directive: `addressedCodes()` expands
                // the selector over the same catalogue `producerOf()` reads, so
                // a code that came out of the expansion has a producer. Kept as
                // a type guard, not as a reason path — the answer below is the
                // same either way, so nothing hangs on which way this exits.
                continue;
            }

            if (
                $this->ruleSelector->isProducerEnabled($producer, $only, $disabled)
                && $activity->ranAtAnyOf($producer, DirectiveLevels::ofGroup($group))
            ) {
                return null;
            }

            $sawDisabledProducer = true;
        }

        // Either every addressable producer was switched off, or nothing
        // addressable was found at all. Those are different answers, and this
        // is where the loop parts them: the second is a directive that was
        // already refused elsewhere.
        return $sawDisabledProducer
            ? DirectiveUnmeasurableReason::ProducerDisabled
            : DirectiveUnmeasurableReason::AlreadyRefused;
    }

    /**
     * The finding codes a target addresses.
     *
     * @return list<string>
     */
    private function addressedCodes(Suppression $suppression): array
    {
        $selector = $suppression->target()->selector();
        if ($selector === null) {
            return [];
        }

        $codes = [];
        foreach ($this->identity->expand($selector->channel()) as $channel) {
            $codes[] = $channel->code;
        }

        return $codes;
    }
}
