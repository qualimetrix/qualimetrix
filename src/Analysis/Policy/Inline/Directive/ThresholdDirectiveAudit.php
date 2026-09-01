<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Inline\Directive;

use LogicException;
use Qualimetrix\Analysis\Finding\Contract\ChannelIdentityInterface;
use Qualimetrix\Analysis\Finding\Contract\Finding;
use Qualimetrix\Analysis\Finding\Contract\Rule\AnalysisContext;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleSelector;
use Qualimetrix\Analysis\Finding\Contract\RuleConfigurationInterface;
use Qualimetrix\Analysis\Finding\Contract\Threshold\ThresholdOverride;
use Qualimetrix\Analysis\Policy\Inline\Contract\Directive\DirectiveVerdict;
use Qualimetrix\Analysis\Policy\Inline\Contract\Directive\ThresholdDirectiveAuditInput;
use Qualimetrix\Analysis\Policy\Inline\Contract\Directive\ThresholdDirectiveAuditInterface;
use Qualimetrix\Core\Path\RelativePath;

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
 */
final readonly class ThresholdDirectiveAudit implements ThresholdDirectiveAuditInterface
{
    /** The one form an author can write, and the only value {@see DirectiveVerdict::$form} takes here. */
    private const string FORM = 'threshold';

    /**
     * Built rather than injected, exactly as {@see DirectiveUsage} builds its
     * level addressing: a pure function of the same universe, with no
     * lifecycle of its own. Asking it — rather than repeating its rules —
     * is what keeps this half and the `annotation.*` channels from disagreeing
     * about which directives were already refused.
     */
    private DirectiveAddressability $addressability;

    public function __construct(
        ChannelIdentityInterface $identity,
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
            $reason = $this->unmeasurableReason($group['bindings'][0], $selection->only, $selection->disabled);

            if ($reason === null) {
                $measurable[] = $group;

                continue;
            }

            $judged[] = [
                'group' => $group,
                'effect' => DirectiveEffect::Unmeasured,
                'reason' => $reason,
                'maskedBy' => null,
            ];
        }

        return self::report([...$judged, ...$this->sweep($input, $measurable)], $input->baselineResult->produced);
    }

    /**
     * One removal per authored directive, bracketed by the control that says
     * the removals mean anything at all.
     *
     * @param list<array{file: string, line: int, rule: string, bindings: list<ThresholdOverride>}> $measurable
     *
     * @return list<array{group: array{file: string, line: int, rule: string, bindings: list<ThresholdOverride>}, effect: DirectiveEffect, reason: ?DirectiveUnmeasurableReason, maskedBy: ?DirectiveSite}>
     */
    private function sweep(ThresholdDirectiveAuditInput $input, array $measurable): array
    {
        if ($measurable === []) {
            return [];
        }

        $baseline = ExecutionFingerprint::of($input->baselineResult->produced);
        $this->assertReproducible($input, $baseline, 'before');

        $effects = [];
        foreach ($measurable as $group) {
            $effects[] = $baseline->compareTo(ExecutionFingerprint::of($this->without($input, [$group])));
        }

        $judged = [];
        foreach ($measurable as $index => $group) {
            $effect = $effects[$index];
            $maskedBy = $effect === DirectiveEffect::Inert
                ? $this->maskedBy($input, $measurable, $index)
                : null;

            $judged[] = [
                'group' => $group,
                'effect' => $maskedBy === null ? $effect : DirectiveEffect::Unmeasured,
                'reason' => $maskedBy === null ? null : DirectiveUnmeasurableReason::Masked,
                'maskedBy' => $maskedBy,
            ];
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
     * @param list<array{file: string, line: int, rule: string, bindings: list<ThresholdOverride>}> $groups
     *
     * @return list<Finding>
     */
    private function without(ThresholdDirectiveAuditInput $input, array $groups): array
    {
        $overrides = $input->baseline->thresholdOverrides;

        foreach ($groups as $group) {
            $overrides[$group['file']] = array_values(array_filter(
                $overrides[$group['file']] ?? [],
                static fn(ThresholdOverride $override): bool => $override->line !== $group['line']
                    || $override->rulePattern !== $group['rule'],
            ));
        }

        return $input->executor->execute(new AnalysisContext(
            metrics: $input->baseline->metrics,
            dependencyGraph: $input->baseline->dependencyGraph,
            namespaceTree: $input->baseline->namespaceTree,
            thresholdOverrides: $overrides,
        ))->produced;
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
     * @param list<string> $only
     * @param list<string> $disabled
     */
    private function unmeasurableReason(
        ThresholdOverride $override,
        array $only,
        array $disabled,
    ): ?DirectiveUnmeasurableReason {
        if ($this->addressability->problemWithThreshold($override) !== null) {
            return DirectiveUnmeasurableReason::AlreadyRefused;
        }

        $enabled = $this->ruleSelector->isProducerEnabled($override->rulePattern, $only, $disabled)
            && !$this->ruleConfiguration->isRuleDisabledByOptions($override->rulePattern);

        return $enabled ? null : DirectiveUnmeasurableReason::ProducerDisabled;
    }

    /**
     * The verdicts, once every removal has been judged: boundary observability
     * read off the baseline, and the whole list in the order an author reads a
     * tree.
     *
     * @param list<array{group: array{file: string, line: int, rule: string, bindings: list<ThresholdOverride>}, effect: DirectiveEffect, reason: ?DirectiveUnmeasurableReason, maskedBy: ?DirectiveSite}> $judged
     * @param list<Finding> $produced
     *
     * @return list<DirectiveVerdict>
     */
    private static function report(array $judged, array $produced): array
    {
        $verdicts = [];

        foreach ($judged as $entry) {
            $group = $entry['group'];

            $verdicts[] = new DirectiveVerdict(
                site: self::site($group),
                effect: $entry['effect'],
                reason: $entry['reason'],
                maskedBy: $entry['maskedBy'],
                boundaryObservable: $entry['effect'] === DirectiveEffect::Overrun
                    || self::boundaryObservable($group, $produced),
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
     * Leave-one-out is blind to mutual masking by construction. A class
     * directive materialises on the class **and on every method in it**
     * (`DeclarationControlBindings`), a method directive materialises on the
     * method, and `AnalysisContext::getThresholdOverride()` picks one by
     * specificity: when they would give the same answer, removing any one
     * changes nothing, and each would be called inert although removing them
     * all changes the run.
     *
     * **The question asked is differential, and that is what keeps the answer
     * about this directive.** Not "does removing the whole coalition change
     * the run" — a live neighbour would move the outcome on its own account
     * and drag every dead annotation beside it into a refusal. What is asked
     * is whether removing *this* directive changes anything **once its
     * maskers are already gone**: the two runs compared are the coalition
     * without this directive and the coalition with it, and everything the
     * neighbours do cancels between them.
     *
     * **The unit is every directive that could hide this one, which is one
     * hop and not a closure.** A directive can only mask what it covers, so a
     * masker shares a subject with this one by definition; a directive two
     * hops away touches subjects this one does not, and the differential
     * comparison cancels it either way. Specificity has four steps, so one
     * subject can carry a class docblock, a property docblock and a property
     * hook's docblock at once — with three, no single removal and no pair
     * moves the outcome while the triple does, which is what makes the unit a
     * set rather than a pair.
     *
     * @param list<array{file: string, line: int, rule: string, bindings: list<ThresholdOverride>}> $measurable
     */
    private function maskedBy(
        ThresholdDirectiveAuditInput $input,
        array $measurable,
        int $index,
    ): ?DirectiveSite {
        $maskers = self::overlapping($measurable, $index);

        if ($maskers === []) {
            return null;
        }

        $withoutMaskers = ExecutionFingerprint::of($this->without($input, $maskers));
        $withoutAll = ExecutionFingerprint::of($this->without($input, [...$maskers, $measurable[$index]]));

        if ($withoutMaskers->compareTo($withoutAll) === DirectiveEffect::Inert) {
            return null;
        }

        return $this->hiddenBy($input, $measurable[$index], $maskers);
    }

    /**
     * Which of the maskers is doing the hiding, asked one at a time.
     *
     * Naming the first neighbour by position would let a report call a
     * directive the masker of another on the same page where it calls that
     * neighbour dead. So each is put back on its own — everything else
     * removed — and the one that still makes this directive's removal
     * invisible is the one named.
     *
     * When no single neighbour does it alone, the hiding is joint and there is
     * no one directive to name; the report then names the first, and that is
     * the only case where the name is positional rather than measured.
     *
     * @param array{file: string, line: int, rule: string, bindings: list<ThresholdOverride>} $group
     * @param list<array{file: string, line: int, rule: string, bindings: list<ThresholdOverride>, site: DirectiveSite}> $maskers
     */
    private function hiddenBy(ThresholdDirectiveAuditInput $input, array $group, array $maskers): DirectiveSite
    {
        if (\count($maskers) === 1) {
            return $maskers[0]['site'];
        }

        foreach ($maskers as $candidate) {
            $others = array_values(array_filter(
                $maskers,
                static fn(array $masker): bool => $masker['site'] !== $candidate['site'],
            ));

            $withOnlyCandidate = ExecutionFingerprint::of($this->without($input, $others));
            $andWithoutTheDirective = ExecutionFingerprint::of($this->without($input, [...$others, $group]));

            if ($withOnlyCandidate->compareTo($andWithoutTheDirective) === DirectiveEffect::Inert) {
                return $candidate['site'];
            }
        }

        return $maskers[0]['site'];
    }

    /**
     * Every other directive of the same rule bound to a subject this one also
     * covers, in the order an author reads them.
     *
     * @param list<array{file: string, line: int, rule: string, bindings: list<ThresholdOverride>}> $measurable
     *
     * @return list<array{file: string, line: int, rule: string, bindings: list<ThresholdOverride>, site: DirectiveSite}>
     */
    private static function overlapping(array $measurable, int $index): array
    {
        $group = $measurable[$index];
        $subjects = self::subjects($group);
        $maskers = [];

        foreach ($measurable as $position => $candidate) {
            if ($position === $index || $candidate['rule'] !== $group['rule']) {
                continue;
            }

            if (array_intersect($subjects, self::subjects($candidate)) === []) {
                continue;
            }

            $maskers[] = [...$candidate, 'site' => self::site($candidate)];
        }

        return $maskers;
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
     * because what it can read is the field, and the field is absent; the
     * warning costs a reader a second look and never costs them a finding.
     *
     * @param array{file: string, line: int, rule: string, bindings: list<ThresholdOverride>} $group
     * @param list<Finding> $produced
     */
    private static function boundaryObservable(array $group, array $produced): bool
    {
        $subjects = self::subjects($group);

        foreach ($produced as $finding) {
            if (
                $finding->threshold === null
                && $finding->ruleName === $group['rule']
                && \in_array($finding->subject->toCanonical(), $subjects, true)
            ) {
                return false;
            }
        }

        return true;
    }

    /** @param array{file: string, line: int, rule: string, bindings: list<ThresholdOverride>} $group */
    private static function site(array $group): DirectiveSite
    {
        return new DirectiveSite(
            file: RelativePath::fromString($group['file']),
            line: $group['line'],
            form: self::FORM,
            target: $group['rule'],
        );
    }

    /**
     * @param array{file: string, line: int, rule: string, bindings: list<ThresholdOverride>} $group
     *
     * @return list<string>
     */
    private static function subjects(array $group): array
    {
        return array_values(array_unique(array_map(
            static fn(ThresholdOverride $override): string => $override->subject->toCanonical(),
            $group['bindings'],
        )));
    }

    /**
     * The bindings of one authored annotation, kept together.
     *
     * The key is the one {@see InlineDirectivePolicy::authoredThresholdOverrides()}
     * uses — line and rule name — because the unit an author edits is the tag
     * they wrote, not the declarations it was expanded onto. Removing the first
     * binding instead of the group would leave the annotation in force on every
     * other declaration it governs and call it inert.
     *
     * @param array<string, list<ThresholdOverride>> $byFile
     *
     * @return list<array{file: string, line: int, rule: string, bindings: list<ThresholdOverride>}>
     */
    private static function groupByAuthoredSite(array $byFile): array
    {
        $groups = [];

        foreach ($byFile as $file => $overrides) {
            foreach ($overrides as $override) {
                $key = $file . "\0" . $override->line . "\0" . $override->rulePattern;
                $groups[$key] ??= [
                    'file' => $file,
                    'line' => $override->line,
                    'rule' => $override->rulePattern,
                    'bindings' => [],
                ];
                $groups[$key]['bindings'][] = $override;
            }
        }

        return array_values($groups);
    }
}
