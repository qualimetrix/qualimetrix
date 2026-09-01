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
                ? $this->maskedBy($input, $baseline, $measurable, $index)
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
        $repeat = ExecutionFingerprint::of($input->executor->execute($input->baseline)->produced);

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
                boundaryObservable: self::boundaryObservable($group, $produced),
            );
        }

        usort(
            $verdicts,
            static fn(DirectiveVerdict $left, DirectiveVerdict $right): int
                => [$left->site->file->value(), $left->site->line, $left->site->target]
                <=> [$right->site->file->value(), $right->site->line, $right->site->target],
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
     * specificity: when both would give the same answer, removing either alone
     * changes nothing, and both would be called inert although removing both
     * changes the run.
     *
     * **Overlap is the question, not the answer, and the answer costs a pass.**
     * Sharing a subject only makes masking possible. Confirming it by asking
     * whether the rule reported on that subject in the baseline gets the main
     * case backwards: two directives silencing one finding leave nothing
     * reported there *because* they work. So each overlapping neighbour is
     * removed together with this directive and the rules are executed once
     * more — a moved outcome is the masking, an unmoved one says the pair does
     * nothing and this directive is inert for real.
     *
     * The test is conservative on purpose. In a pair where the neighbour does
     * something on its own, the joint removal moves the outcome partly on the
     * neighbour's account, and this directive is still called masked —
     * refusing to answer costs an author nothing, while advising them to
     * delete an annotation whose removal alongside its neighbour changes the
     * run costs them the finding.
     *
     * @param list<array{file: string, line: int, rule: string, bindings: list<ThresholdOverride>}> $measurable
     */
    private function maskedBy(
        ThresholdDirectiveAuditInput $input,
        ExecutionFingerprint $baseline,
        array $measurable,
        int $index,
    ): ?DirectiveSite {
        $group = $measurable[$index];
        $subjects = self::subjects($group);

        foreach ($measurable as $position => $neighbour) {
            if ($position === $index || $neighbour['rule'] !== $group['rule']) {
                continue;
            }

            if (array_intersect($subjects, self::subjects($neighbour)) === []) {
                continue;
            }

            $paired = $baseline->compareTo(ExecutionFingerprint::of($this->without($input, [$group, $neighbour])));
            if ($paired !== DirectiveEffect::Inert) {
                return self::site($neighbour);
            }
        }

        return null;
    }

    /**
     * Whether an unfulfilled promise would have been visible here at all.
     *
     * False exactly when the addressed rule reported on a subject this
     * directive covers and published no boundary with the finding: the
     * fingerprint of such a rule cannot move when only the boundary moves, so
     * an `Inert` verdict there is indistinguishable from a boundary the
     * measured value had already passed. Nine of the twenty-seven rule files
     * publish no boundary and four of those support overrides; the flag is
     * read off the findings rather than off a list of those four names, which
     * would drift from the tree without a word.
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
