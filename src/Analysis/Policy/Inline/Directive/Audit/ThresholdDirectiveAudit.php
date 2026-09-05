<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Inline\Directive\Audit;

use LogicException;
use Qualimetrix\Analysis\Finding\Contract\ChannelDeclarationRegistryInterface;
use Qualimetrix\Analysis\Finding\Contract\ChannelIdentityInterface;
use Qualimetrix\Analysis\Finding\Contract\Finding;
use Qualimetrix\Analysis\Finding\Contract\LevelActivity;
use Qualimetrix\Analysis\Finding\Contract\Rule\AnalysisContext;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleSelector;
use Qualimetrix\Analysis\Finding\Contract\RuleConfigurationInterface;
use Qualimetrix\Analysis\Finding\Contract\Threshold\ThresholdOverride;
use Qualimetrix\Analysis\Policy\Inline\Contract\Directive\DirectiveEffect;
use Qualimetrix\Analysis\Policy\Inline\Contract\Directive\DirectiveSweepScope;
use Qualimetrix\Analysis\Policy\Inline\Contract\Directive\DirectiveUnmeasurableReason;
use Qualimetrix\Analysis\Policy\Inline\Contract\Directive\DirectiveVerdict;
use Qualimetrix\Analysis\Policy\Inline\Contract\Directive\ThresholdDirectiveAuditInput;
use Qualimetrix\Analysis\Policy\Inline\Contract\Directive\ThresholdDirectiveAuditInterface;
use Qualimetrix\Analysis\Policy\Inline\Directive\DirectiveAddressability;
use Qualimetrix\Core\Symbol\SymbolLevel;
use Qualimetrix\Core\Symbol\SymbolLevelProjection;

/**
 * The threshold half of the inline-directive subject, answered by difference
 * of outcome.
 *
 * Nothing a rule publishes says which boundary it decided with, so the only
 * observable a `@qmx-threshold` has is the run it changes. Each authored
 * directive is removed on its own and the rules are executed again over the
 * context the run already prepared; what the two executions produced is
 * compared as a whole.
 *
 * The method's own assumption — that re-executing the rules on an unchanged
 * context reproduces the run — is not taken on trust. A sweep begins and ends
 * with the full override set, and both control passes must reproduce the
 * baseline exactly; a drift between them is shared state in the rules, which
 * invalidates every verdict rather than any one directive.
 *
 * **A counterfactual executes only the rule the directive addresses**, unless
 * the caller asked for {@see DirectiveSweepScope::Full}. A directive names one
 * rule by exact name, so the other rules would be executed only to be compared
 * against themselves. Two things keep the narrowing honest and both are here
 * rather than in a comment: the baseline a counterfactual is compared against
 * is taken by the same narrowing ({@see reference()}), and a rule executed on
 * its own must reproduce what it produced inside the whole run
 * ({@see assertNarrowingChangedNothing()}). Neither sees a directive of one
 * rule moving a finding of another: a narrowed sweep never executes the other
 * rule, so no comparison here contains it. `Full` compares the two scopes
 * verdict for verdict, not finding for finding, and is silent exactly where a
 * moved finding does not cross a verdict's own category — see
 * `docs/adr/0040-narrow-directive-sweep.md` for what that bound does and does
 * not cover. What holds the claim up is structural, not measured: **a rule
 * cannot read another rule's directive.** Inside the rule layer,
 * {@see AnalysisContext::getThresholdOverride()} is the only accessor a rule
 * calls, and every call site passes the calling rule's own name — held by
 * `ThresholdOverrideOwnRuleNameGuardTest`, which reddens on a foreign name.
 * That guard also names this class as one of two legitimate direct readers of
 * the override map: it reads and rewrites `$input->baseline->thresholdOverrides`
 * below to build each counterfactual, which is the map's owning subject doing
 * its job, not a rule reading a neighbour's directive. The exception is
 * exercised, not stated — the guard sees this read and reddens on it the moment
 * the name is removed from its list.
 *
 * **This is the one caller that makes
 * {@see \Qualimetrix\Analysis\Finding\Contract\RuleExecutionInterface::execute()}'s
 * narrowing parameter safe by construction, not merely by contract.** Every
 * name this class passes as `$restrictToProducer` comes from an authored
 * `@qmx-threshold`'s own rule name ({@see narrowedTo()}), and a classless
 * producer of the computed-metric family can never own one —
 * `ComputedMetricChannelFamily::SUPPORTS_THRESHOLD_OVERRIDE` is `false` for
 * all seven, refused earlier by {@see unmeasurableReason()} before a name ever
 * reaches `execute()`. {@see \Qualimetrix\Analysis\Finding\RuleExecution::published()}'s
 * own half of the narrowed result — the per-channel filter — is likewise
 * never read here: {@see without()} reads only `->produced`. A second caller
 * narrowing for a different reason would need to revisit both assumptions.
 *
 * @qmx-threshold coupling.cbo 21 -- Raw CBO 20: an audit that answers what a directive did must
 *                name every vocabulary the answer is spelled in — verdict, effect, unmeasurable
 *                reason, sweep scope, authored group — plus the two run-side interfaces its single
 *                identity argument intersects, because deciding addressability needs both a
 *                channel's identity and its declaration. Those are the alphabet of the answer, not
 *                collaborators it delegates work to. 21 gets one-edge headroom.
 */
final readonly class ThresholdDirectiveAudit implements ThresholdDirectiveAuditInterface
{
    /**
     * Built rather than injected, exactly as {@see DirectiveUsage} builds its
     * level addressing: a pure function of the same universe, with no
     * lifecycle of its own. Asking it — rather than repeating its rules —
     * is what keeps this half and the `annotation.*` channels from disagreeing
     * about which directives were already refused.
     */
    private DirectiveAddressability $addressability;

    public function __construct(
        ChannelIdentityInterface&ChannelDeclarationRegistryInterface $identity,
        private RuleSelector $ruleSelector,
        private RuleConfigurationInterface $ruleConfiguration,
    ) {
        $this->addressability = new DirectiveAddressability($identity);
    }

    public function verdicts(ThresholdDirectiveAuditInput $input): array
    {
        $groups = self::groupByAuthoredSite($input->baseline->thresholdOverrides);
        $selection = $this->ruleConfiguration->selection();

        $judged = [];
        $measurable = [];

        foreach ($groups as $group) {
            $reason = $this->unmeasurableReason(
                $group->bindings,
                $input->baselineResult->levelActivity,
                $selection->only,
                $selection->disabled,
            );

            if ($reason === null) {
                $measurable[] = $group;

                continue;
            }

            $judged[] = new MaskingOutcome($group, DirectiveEffect::Unmeasured, $reason, null);
        }

        return self::report([...$judged, ...$this->sweep($input, $measurable)], $input->baselineResult->produced);
    }

    /**
     * One removal per authored directive, bracketed by the control that says
     * the removals mean anything at all.
     *
     * @param list<AuthoredDirectiveGroup> $measurable
     *
     * @return list<MaskingOutcome>
     */
    private function sweep(ThresholdDirectiveAuditInput $input, array $measurable): array
    {
        if ($measurable === []) {
            return [];
        }

        $baseline = ExecutionFingerprint::of($input->baselineResult->produced);
        $this->assertReproducible($input, $baseline, 'before');

        $references = [];
        $effects = [];
        foreach ($measurable as $group) {
            $rule = $group->rule;
            $references[$rule] ??= $this->reference($input, $rule, $baseline);
            $effects[] = $references[$rule]->compareTo(
                ExecutionFingerprint::of($this->without($input, [$group], self::narrowedTo($input, $rule))),
            );
        }

        $judged = [];
        foreach ($measurable as $index => $group) {
            $effect = $effects[$index];
            $maskedBy = $effect === DirectiveEffect::Inert
                ? $this->maskedBy($input, $measurable, $index)
                : null;

            $judged[] = new MaskingOutcome(
                $group,
                $maskedBy === null ? $effect : DirectiveEffect::Unmeasured,
                $maskedBy === null ? null : DirectiveUnmeasurableReason::Masked,
                $maskedBy,
            );
        }

        $this->assertReproducible($input, $baseline, 'after');

        return $judged;
    }

    /**
     * Every finding the rules produced with the given authored directives —
     * all of their bindings — taken out of the context.
     *
     * The context is rebuilt rather than mutated because it is `final
     * readonly`; everything expensive in it (metrics, graph, namespace tree) is
     * carried over by reference, so the copy costs nothing and the rules see
     * the same measured world with one annotation missing.
     *
     * @param list<AuthoredDirectiveGroup> $groups
     *
     * @return list<Finding>
     */
    private function without(
        ThresholdDirectiveAuditInput $input,
        array $groups,
        ?string $restrictToProducer = null,
    ): array {
        $overrides = $input->baseline->thresholdOverrides;

        foreach ($groups as $group) {
            // The map's own key, not a second spelling of it: every key the run
            // produced is already normalized, so the path the group holds
            // converts back to the bucket the run filled.
            $key = $group->file->value();
            $overrides[$key] = array_values(array_filter(
                $overrides[$key] ?? [],
                static fn(ThresholdOverride $override): bool => $override->line !== $group->line
                    || $override->rulePattern !== $group->rule,
            ));
        }

        return $input->executor->execute(new AnalysisContext(
            metrics: $input->baseline->metrics,
            dependencyGraph: $input->baseline->dependencyGraph,
            namespaceTree: $input->baseline->namespaceTree,
            thresholdOverrides: $overrides,
        ), $restrictToProducer)->produced;
    }

    /**
     * The producer a counterfactual for this rule may execute, or null when
     * the sweep was asked for the full rule layer.
     */
    private static function narrowedTo(ThresholdDirectiveAuditInput $input, string $rule): ?string
    {
        return $input->sweep === DirectiveSweepScope::Narrow ? $rule : null;
    }

    /**
     * The run every counterfactual for this rule is compared against.
     *
     * **Both sides of a comparison must be measured the same way.** Under a
     * narrowed sweep the counterfactual executes one producer, so the baseline
     * it is compared against is that same producer executed the same way — not
     * the full run projected onto the rule's name. A projection would compare
     * a run against a filtered other run and read every difference in how the
     * two were produced as the work of a directive.
     *
     * Taken once per rule and reused across its directives: it is the same run
     * every time, and the sweep's whole point is to stop paying for executions
     * that answer nothing.
     */
    private function reference(
        ThresholdDirectiveAuditInput $input,
        string $rule,
        ExecutionFingerprint $baseline,
    ): ExecutionFingerprint {
        if ($input->sweep === DirectiveSweepScope::Full) {
            return $baseline;
        }

        // Through the same narrowing the counterfactuals use, and not a second
        // expression that happens to spell it the same way: "both sides are
        // measured alike" has to be one decision, or it is two that can part.
        $narrowed = $this->without($input, [], self::narrowedTo($input, $rule));
        $this->assertNarrowingChangedNothing($input, $narrowed, $rule);

        return ExecutionFingerprint::of($narrowed);
    }

    /**
     * The third control, and the one that only a narrowed sweep needs: a rule
     * executed on its own produced what it produced inside the whole run.
     *
     * It is what stands between the narrowing and a silent lie. The narrowing
     * assumes a rule's output does not depend on its neighbours having run;
     * where that fails, every verdict for the rule is measured against a
     * baseline the run never had, and each one would still look perfectly
     * ordinary. The comparison costs nothing — the full baseline is already in
     * hand.
     *
     * **What it can and cannot see.** It sees a rule that behaves differently
     * in isolation. It does **not** see removing a directive of rule X moving
     * a finding of rule Y: a narrowed sweep never executes Y, so neither side
     * of that comparison contains it. That claim is measured by sweeping the
     * tree both ways and comparing verdicts — the control the `Full` scope
     * exists for.
     *
     * The names compared are those the narrowed run produced plus the
     * addressed rule itself, so a rule that produced nothing in isolation
     * while producing findings in the full run is caught rather than filtered
     * out of its own control.
     *
     * @param list<Finding> $narrowed
     */
    private function assertNarrowingChangedNothing(
        ThresholdDirectiveAuditInput $input,
        array $narrowed,
        string $rule,
    ): void {
        $names = [$rule => true];
        foreach ($narrowed as $finding) {
            $names[$finding->ruleName] = true;
        }

        $expected = array_values(array_filter(
            $input->baselineResult->produced,
            static fn(Finding $finding): bool => isset($names[$finding->ruleName]),
        ));

        $narrowedFingerprint = ExecutionFingerprint::of($narrowed);
        $expectedFingerprint = ExecutionFingerprint::of($expected);

        if ($narrowedFingerprint->reproduces($expectedFingerprint)) {
            return;
        }

        throw new LogicException(\sprintf(
            'Executing "%s" on its own did not reproduce what it produced in the full run: %s.'
            . ' A threshold directive cannot be judged against a baseline the run never had, so the'
            . ' narrowed sweep is refused; the disagreement is shared state between rules, not a'
            . ' statement about any directive.',
            $rule,
            implode(', ', $expectedFingerprint->disagreementWith($narrowedFingerprint)),
        ));
    }

    /**
     * The control the whole method rests on: executing the rules again on the
     * unchanged context reproduces the run.
     *
     * Run twice per sweep, before the first removal and after the last one,
     * because a single control cannot see state a rule accumulates across the
     * executions between them.
     */
    private function assertReproducible(
        ThresholdDirectiveAuditInput $input,
        ExecutionFingerprint $baseline,
        string $pass,
    ): void {
        // Through `without()` with nothing removed, not through the original
        // context: the counterfactual passes execute against a rebuilt context,
        // and a control that skips the rebuilding controls a different path
        // from the one it is vouching for.
        $repeat = ExecutionFingerprint::of($this->without($input, []));

        if ($repeat->reproduces($baseline)) {
            return;
        }

        throw new LogicException(\sprintf(
            'Re-executing the rules on the unchanged context did not reproduce the run (%s the sweep):'
            . ' %s. Threshold directives cannot be judged by difference of outcome while a rule carries'
            . ' state across executions.',
            $pass,
            implode(', ', $baseline->disagreementWith($repeat)),
        ));
    }

    /**
     * Why this directive cannot be judged, or null when it can.
     *
     * Two reasons reach a threshold, not the four a suppression can meet. A
     * threshold names one rule by its exact name and takes no level and no
     * group form (ADR 0024 §2), so it can neither address every channel nor
     * expand to several: the "names nothing addressable" family collapses into
     * the one answer {@see DirectiveAddressability} already publishes on
     * `annotation.unresolved-directive` and `annotation.unsupported-threshold`,
     * and repeating it here would judge one mistake twice.
     *
     * Enablement by configuration is **read**, not re-derived: the run records
     * which producer/level pairs it let run ({@see LevelActivity}), and asking
     * the merged configuration again here is what once reported a rule
     * disabled at every level as enabled, leaving a live directive `Inert` on
     * exit code 2.
     *
     * The levels come from the whole authored group rather than its first
     * binding: one authored site expands to a binding per applicable
     * declaration, and those need not share a level. A level the producer does
     * not declare is not counted — absence is not disablement, so
     * `@qmx-threshold coupling.cbo` on a method stays whatever it was instead
     * of becoming `ProducerDisabled`.
     *
     * @param list<ThresholdOverride> $bindings
     * @param list<string> $only
     * @param list<string> $disabled
     */
    private function unmeasurableReason(
        array $bindings,
        LevelActivity $activity,
        array $only,
        array $disabled,
    ): ?DirectiveUnmeasurableReason {
        $override = $bindings[0];

        if ($this->addressability->problemWithThreshold($override) !== null) {
            return DirectiveUnmeasurableReason::AlreadyRefused;
        }

        $enabled = $this->ruleSelector->isProducerEnabled($override->rulePattern, $only, $disabled)
            && $activity->ranAtAnyOf($override->rulePattern, self::levelsOf($bindings));

        return $enabled ? null : DirectiveUnmeasurableReason::ProducerDisabled;
    }

    /**
     * The levels an authored group landed on, read off its bindings' subjects
     * with the same projection {@see \Qualimetrix\Analysis\Finding\Contract\Finding::level()}
     * uses, so the two cannot disagree about what level a subject is.
     *
     * @param list<ThresholdOverride> $bindings
     *
     * @return list<SymbolLevel>
     */
    private static function levelsOf(array $bindings): array
    {
        $levels = [];

        foreach ($bindings as $binding) {
            $level = SymbolLevelProjection::ofDeclaration($binding->subject->toSymbolPath()->getType());
            $levels[$level->value] = $level;
        }

        return array_values($levels);
    }

    /**
     * The verdicts, once every removal has been judged: boundary observability
     * read off the baseline, and the whole list in the order an author reads a
     * tree.
     *
     * @param list<MaskingOutcome> $judged
     * @param list<Finding> $produced
     *
     * @return list<DirectiveVerdict>
     */
    private static function report(array $judged, array $produced): array
    {
        $verdicts = [];

        foreach ($judged as $entry) {
            $verdicts[] = new DirectiveVerdict(
                site: $entry->group->site(),
                effect: $entry->effect,
                reason: $entry->reason,
                maskedBy: $entry->maskedBy?->site(),
                boundaryObservable: $entry->effect === DirectiveEffect::Overrun
                    || self::boundaryObservable($entry->group, $produced),
            );
        }

        usort(
            $verdicts,
            static fn(DirectiveVerdict $left, DirectiveVerdict $right): int
                => [$left->site->file->value(), $left->site->line, $left->site->form, $left->site->target]
                <=> [$right->site->file->value(), $right->site->line, $right->site->form, $right->site->target],
        );

        return $verdicts;
    }

    /**
     * The directive that makes this one's removal invisible, or null.
     *
     * The coalition reasoning — which neighbours overlap, whether the
     * coalition without them is inert, and which one of several is doing the
     * hiding — is a subject of its own, answered by {@see DirectiveMaskingCoalition}
     * rather than here. What stays here is the one thing that class cannot
     * do itself: execute a counterfactual against the run this sweep owns.
     *
     * Every masker is a directive of the same rule by construction
     * ({@see DirectiveMaskingCoalition}'s own overlap test), so the narrowing
     * that serves the directive serves the whole coalition.
     *
     * @param list<AuthoredDirectiveGroup> $measurable
     */
    private function maskedBy(
        ThresholdDirectiveAuditInput $input,
        array $measurable,
        int $index,
    ): ?AuthoredDirectiveGroup {
        $restrictToProducer = self::narrowedTo($input, $measurable[$index]->rule);

        $coalition = new DirectiveMaskingCoalition(
            fn(array $groups, ?string $forProducer): array => $this->without($input, $groups, $forProducer),
        );

        return $coalition->maskedBy($measurable, $index, $restrictToProducer);
    }

    /**
     * Whether an unfulfilled promise would have been visible here at all.
     *
     * False when the addressed rule reported on a subject this
     * directive covers and published no boundary with the finding: the
     * fingerprint of such a rule cannot move when only the boundary moves, so
     * an `Inert` verdict there is indistinguishable from a boundary the
     * measured value had already passed. Nine of the twenty-seven rule files
     * publish no boundary and four of those support overrides; the flag is
     * read off the findings rather than off a list of those four names, which
     * would drift from the tree without a word.
     *
     * A verdict of {@see DirectiveEffect::Overrun} overrides this to true at
     * the call site, and not as a courtesy: the boundary half of the
     * fingerprint carries the prose as well as the field, so a rule that
     * spells its boundary into a message or a recommendation can produce an
     * observed boundary difference while publishing no field. Answering "the
     * question could not be asked" beside an answer to it would be a report
     * contradicting itself.
     *
     * Beside an `Inert` verdict the flag is cautious rather than wrong. An
     * inert verdict already means the two runs produced the same findings, so
     * a rule that spells its boundary into prose has shown that boundary
     * standing still — there was no overrun to miss. The flag still warns,
     * because what it can read is the field, and the field is absent.
     *
     * **It is no longer free, and that is a deliberate trade.** The command
     * refuses to fail a build on an inert verdict carrying this flag, so a
     * genuinely dead directive on such a rule goes unenforced. The alternative
     * is worse in the direction that matters: failing on it demands the author
     * delete an annotation on the strength of a question the report itself
     * says was never asked. Cautious here means silent, not wrong.
     *
     * @param list<Finding> $produced
     */
    private static function boundaryObservable(AuthoredDirectiveGroup $group, array $produced): bool
    {
        foreach ($produced as $finding) {
            if (
                $finding->threshold === null
                && $finding->ruleName === $group->rule
                && $group->covers($finding->subject)
            ) {
                return false;
            }
        }

        return true;
    }

    /**
     * The bindings of one authored annotation, kept together.
     *
     * The key is the one {@see \Qualimetrix\Analysis\Policy\Inline\Directive\InlineDirectivePolicy::authoredThresholdOverrides()}
     * uses — line and rule name — because the unit an author edits is the tag
     * they wrote, not the declarations it was expanded onto. Removing the first
     * binding instead of the group would leave the annotation in force on every
     * other declaration it governs and call it inert.
     *
     * The bindings are gathered into a keyed map first and the groups built
     * from it afterwards, because gathering is incremental and the group is
     * not: a binding joins a site already seen, which an immutable object
     * cannot be asked to do.
     *
     * @param array<string, list<ThresholdOverride>> $byFile
     *
     * @return list<AuthoredDirectiveGroup>
     */
    private static function groupByAuthoredSite(array $byFile): array
    {
        /** @var array<string, array{file: string, line: int, rule: string, bindings: list<ThresholdOverride>}> $gathered */
        $gathered = [];

        foreach ($byFile as $file => $overrides) {
            foreach ($overrides as $override) {
                $key = $file . "\0" . $override->line . "\0" . $override->rulePattern;
                $gathered[$key] ??= [
                    'file' => $file,
                    'line' => $override->line,
                    'rule' => $override->rulePattern,
                    'bindings' => [],
                ];
                $gathered[$key]['bindings'][] = $override;
            }
        }

        return array_values(array_map(
            static fn(array $group): AuthoredDirectiveGroup => AuthoredDirectiveGroup::of(
                $group['file'],
                $group['line'],
                $group['rule'],
                $group['bindings'],
            ),
            $gathered,
        ));
    }
}
