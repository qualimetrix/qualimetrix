<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Policy\Inline\Integration;

use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Definition\ResolvedComputedMetricDefinitions;
use Qualimetrix\Analysis\Evidence\Measurement\Repository\InMemoryMetricRepository;
use Qualimetrix\Analysis\Finding\Contract\ChannelUniverseInterface;
use Qualimetrix\Analysis\Finding\Contract\Configuration\FindingCliOverrides;
use Qualimetrix\Analysis\Finding\Contract\Configuration\FindingConfiguration;
use Qualimetrix\Analysis\Finding\Contract\Control\ControlScope;
use Qualimetrix\Analysis\Finding\Contract\Rule\AnalysisContext;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleSelector;
use Qualimetrix\Analysis\Finding\Contract\RuleOptionsDocument;
use Qualimetrix\Analysis\Finding\Contract\RuleSelection;
use Qualimetrix\Analysis\Finding\Contract\Threshold\ThresholdOverride;
use Qualimetrix\Analysis\Finding\Rule\InMemoryRuleChannelRegistry;
use Qualimetrix\Analysis\Finding\RuleConfiguration\RuleOptionsRegistry;
use Qualimetrix\Analysis\Policy\Inline\Contract\Directive\DirectiveEffect;
use Qualimetrix\Analysis\Policy\Inline\Contract\Directive\DirectiveSweepScope;
use Qualimetrix\Analysis\Policy\Inline\Contract\Directive\DirectiveUnmeasurableReason;
use Qualimetrix\Analysis\Policy\Inline\Contract\Directive\DirectiveVerdict;
use Qualimetrix\Analysis\Policy\Inline\Contract\Directive\ThresholdDirectiveAuditInput;
use Qualimetrix\Analysis\Policy\Inline\Directive\ThresholdDirectiveAudit;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\DeclarationOrdinal;
use Qualimetrix\Core\Symbol\DeclarationPath;
use Qualimetrix\Core\Symbol\MetricSubject;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Infrastructure\DependencyInjection\ContainerFactory;
use Qualimetrix\Infrastructure\Rule\Contract\RuleChannelSnapshotFactoryInterface;
use Qualimetrix\Tests\Analysis\Policy\Inline\Support\MultiRuleThresholdRuleExecution;
use Qualimetrix\Tests\Analysis\Policy\Inline\Support\ScriptedThresholdRuleExecution;

/**
 * What a `@qmx-threshold` did, answered by executing the rules again without
 * it.
 *
 * Every case here drives a rule that really reads the override off the
 * context, because the claim under test is about a difference between two
 * executions and a canned executor cannot produce one.
 */
#[CoversClass(ThresholdDirectiveAudit::class)]
final class ThresholdDirectiveAuditTest extends TestCase
{
    private const string FILE = 'src/Sample.php';

    private const string RULE = 'coupling.cbo';

    #[Test]
    public function itCallsADirectiveEffectiveWhenRemovingItChangesWhatTheRulesProduced(): void
    {
        $subject = self::subject('Widget', 'render');
        $executor = self::executor([['subject' => $subject, 'value' => 25]]);

        $verdicts = self::audit($executor, [self::override(10, $subject, warning: 30, error: 40)]);

        self::assertCount(1, $verdicts);
        self::assertSame(DirectiveEffect::Effective, $verdicts[0]->effect);
        self::assertSame(self::RULE, $verdicts[0]->site->target);
        self::assertSame('threshold', $verdicts[0]->site->form);
    }

    /**
     * The pair to the case above: the same shape of run, the same executor,
     * and a directive that moves nothing. Without it, a detector that answered
     * `Effective` unconditionally would pass the first case.
     */
    #[Test]
    public function itCallsADirectiveInertWhenRemovingItChangesNothing(): void
    {
        $subject = self::subject('Widget', 'render');
        $executor = self::executor([['subject' => $subject, 'value' => 5]]);

        $verdicts = self::audit($executor, [self::override(10, $subject, warning: 30, error: 40)]);

        self::assertSame(DirectiveEffect::Inert, $verdicts[0]->effect);
        self::assertNull($verdicts[0]->maskedBy);
    }

    /**
     * The finding fires either way and only the boundary it names moves: the
     * directive applied and the promised relief never happened.
     */
    #[Test]
    public function itCallsADirectiveOverrunWhenOnlyTheBoundaryMoved(): void
    {
        $subject = self::subject('Widget', 'render');
        $executor = self::executor([['subject' => $subject, 'value' => 100]]);

        $verdicts = self::audit($executor, [self::override(10, $subject, warning: 50, error: 60)]);

        self::assertSame(DirectiveEffect::Overrun, $verdicts[0]->effect);
        self::assertTrue($verdicts[0]->boundaryObservable);
    }

    #[Test]
    public function itRefusesToJudgeADirectiveWhoseProducerIsDisabled(): void
    {
        $subject = self::subject('Widget', 'render');
        $executor = self::executor([['subject' => $subject, 'value' => 25]]);

        $verdicts = self::audit(
            $executor,
            [self::override(10, $subject, warning: 30, error: 40)],
            new RuleSelection(disabled: [self::RULE]),
        );

        self::assertSame(DirectiveEffect::Unmeasured, $verdicts[0]->effect);
        self::assertSame(DirectiveUnmeasurableReason::ProducerDisabled, $verdicts[0]->reason);
        self::assertSame(1, $executor->executions, 'only the run itself: an unaskable directive costs no sweep');
    }

    #[Test]
    public function itRefusesToJudgeADirectiveNamingNoRule(): void
    {
        $subject = self::subject('Widget', 'render');
        $executor = self::executor([['subject' => $subject, 'value' => 25]]);

        $verdicts = self::audit($executor, [
            new ThresholdOverride('nowhere.at-all', 30, 40, 10, $subject, ControlScope::Callable),
        ]);

        self::assertSame(DirectiveEffect::Unmeasured, $verdicts[0]->effect);
        self::assertSame(DirectiveUnmeasurableReason::AlreadyRefused, $verdicts[0]->reason);
    }

    /**
     * One annotation on a class docblock is materialised on the class and on
     * every method in it. Removing the first of those bindings instead of all
     * of them leaves the annotation in force where it mattered and calls it
     * inert; the finding here hangs on the third binding, so that mistake
     * shows up as `Inert`.
     */
    #[Test]
    public function itRemovesEveryBindingOfOneAuthoredSite(): void
    {
        $class = self::subject('Widget');
        $first = self::subject('Widget', 'mount');
        $third = self::subject('Widget', 'render');
        $executor = self::executor([['subject' => $third, 'value' => 25]]);

        $verdicts = self::audit($executor, [
            self::override(10, $class, warning: 30, error: 40, scope: ControlScope::Class_),
            self::override(10, $first, warning: 30, error: 40, scope: ControlScope::Class_),
            self::override(10, $third, warning: 30, error: 40, scope: ControlScope::Class_),
        ]);

        self::assertCount(1, $verdicts, 'three bindings of one annotation are one directive');
        self::assertSame(DirectiveEffect::Effective, $verdicts[0]->effect);
        // Baseline (from the test helper) + before-control + narrowed
        // reference + one removal + after-control = 5. The narrowed sweep
        // adds the reference pass, which a Full sweep would have gotten for
        // free from the baseline already in hand.
        self::assertSame(5, $executor->executions, 'baseline, before-control, narrowed reference, removal, after-control');
    }

    /**
     * Two directives covering one subject with the same answer: removing
     * either alone is invisible, and calling both inert would advise removing
     * both, which changes the run.
     */
    #[Test]
    public function itRefusesToJudgeEitherDirectiveOfAMaskingPair(): void
    {
        $class = self::subject('Widget');
        $method = self::subject('Widget', 'render');
        $executor = self::executor([['subject' => $method, 'value' => 25]]);

        $verdicts = self::audit($executor, [
            self::override(5, $class, warning: 30, error: 40, scope: ControlScope::Class_),
            self::override(5, $method, warning: 30, error: 40, scope: ControlScope::Class_),
            self::override(9, $method, warning: 30, error: 40),
        ]);

        self::assertCount(2, $verdicts);
        self::assertSame(
            [DirectiveEffect::Unmeasured, DirectiveEffect::Unmeasured],
            array_map(static fn(DirectiveVerdict $v): DirectiveEffect => $v->effect, $verdicts),
        );
        self::assertSame(
            [DirectiveUnmeasurableReason::Masked, DirectiveUnmeasurableReason::Masked],
            array_map(static fn(DirectiveVerdict $v): ?DirectiveUnmeasurableReason => $v->reason, $verdicts),
        );
        self::assertSame(9, $verdicts[0]->maskedBy?->line);
        self::assertSame(5, $verdicts[1]->maskedBy?->line);
        self::assertSame(self::FILE, $verdicts[0]->maskedBy->file->value());
    }

    /**
     * Specificity has four steps, so one subject can carry directives from a
     * class docblock, a property docblock and a property hook's docblock at
     * once. With three of them nothing moves when any one is removed **and
     * nothing moves when any pair is** — only the whole coalition does, which
     * is why the pass removes the component and not a pair. Answering `Inert`
     * to each of the three is advice to delete all three, and that changes the
     * run.
     */
    #[Test]
    public function itRefusesToJudgeADirectiveMaskedOnlyByTwoNeighboursTogether(): void
    {
        $subject = self::subject('Widget', 'render');
        $executor = self::executor([['subject' => $subject, 'value' => 25]]);

        $verdicts = self::audit($executor, [
            self::override(5, $subject, warning: 30, error: 40, scope: ControlScope::Class_),
            self::override(9, $subject, warning: 30, error: 40, scope: ControlScope::Callable),
            self::override(13, $subject, warning: 30, error: 40, scope: ControlScope::Hook),
        ]);

        self::assertSame(
            [DirectiveEffect::Unmeasured, DirectiveEffect::Unmeasured, DirectiveEffect::Unmeasured],
            array_map(static fn(DirectiveVerdict $v): DirectiveEffect => $v->effect, $verdicts),
        );
        self::assertSame(
            [DirectiveUnmeasurableReason::Masked, DirectiveUnmeasurableReason::Masked, DirectiveUnmeasurableReason::Masked],
            array_map(static fn(DirectiveVerdict $v): ?DirectiveUnmeasurableReason => $v->reason, $verdicts),
        );
    }

    /**
     * The control for the case above, and the reason it is not vacuous: with
     * only two of the three, a pair pass would have been enough, so the triple
     * has to be the thing that separates the two implementations.
     */
    #[Test]
    public function itStillJudgesEachDirectiveOfAThreeWayCoalitionThatChangesNothingTogether(): void
    {
        $subject = self::subject('Widget', 'render');
        $executor = self::executor([['subject' => $subject, 'value' => 5]]);

        $verdicts = self::audit($executor, [
            self::override(5, $subject, warning: 30, error: 40, scope: ControlScope::Class_),
            self::override(9, $subject, warning: 30, error: 40, scope: ControlScope::Callable),
            self::override(13, $subject, warning: 30, error: 40, scope: ControlScope::Hook),
        ]);

        self::assertSame(
            [DirectiveEffect::Inert, DirectiveEffect::Inert, DirectiveEffect::Inert],
            array_map(static fn(DirectiveVerdict $v): DirectiveEffect => $v->effect, $verdicts),
        );
    }

    /**
     * A directive that moves a finding from warning to error moves the
     * outcome, not the boundary: severity belongs to what a finding is.
     */
    #[Test]
    public function itCallsADirectiveEffectiveWhenItOnlyMovedTheSeverity(): void
    {
        $subject = self::subject('Widget', 'render');
        $executor = self::executor([['subject' => $subject, 'value' => 45]]);

        // Default: 45 >= error 40, so Error. With the directive: warning 20
        // stays, error rises past the value, so the same finding is a Warning.
        $verdicts = self::audit($executor, [self::override(10, $subject, warning: 20, error: 60)]);

        self::assertSame(DirectiveEffect::Effective, $verdicts[0]->effect);
    }

    /**
     * The other way to switch a producer off. `disabled_rules` stops the rule
     * from running; `rules: { X: false }` lets it run and return nothing, and
     * the author made the same decision either way — reading only the first is
     * what once reported every annotation of a switched-off rule as leftover.
     */
    #[Test]
    public function itRefusesToJudgeADirectiveWhoseProducerIsOffThroughItsOptions(): void
    {
        $subject = self::subject('Widget', 'render');
        $executor = self::executor([['subject' => $subject, 'value' => 25]]);
        $registry = new RuleOptionsRegistry();
        $registry->replace(new FindingConfiguration(
            new RuleOptionsDocument([self::RULE => ['enabled' => false]]),
            new FindingCliOverrides([]),
            new RuleSelection(),
        ));

        $verdicts = self::auditWith($executor, [self::override(10, $subject, warning: 30, error: 40)], $registry);

        self::assertSame(DirectiveEffect::Unmeasured, $verdicts[0]->effect);
        self::assertSame(DirectiveUnmeasurableReason::ProducerDisabled, $verdicts[0]->reason);
        self::assertSame(1, $executor->executions, 'only the run itself: an unaskable directive costs no sweep');
    }

    /**
     * The same overlap over a subject the rule never reports on under any
     * directive. Nothing is being masked there, and answering `Masked` would
     * replace a true "does nothing" with a refusal.
     */
    #[Test]
    public function itDoesNotCallAPairMaskedWhereTheRuleNeverReports(): void
    {
        $class = self::subject('Widget');
        $method = self::subject('Widget', 'render');
        $executor = self::executor([['subject' => $method, 'value' => 5]]);

        $verdicts = self::audit($executor, [
            self::override(5, $class, warning: 30, error: 40, scope: ControlScope::Class_),
            self::override(5, $method, warning: 30, error: 40, scope: ControlScope::Class_),
            self::override(9, $method, warning: 30, error: 40),
        ]);

        self::assertSame(
            [DirectiveEffect::Inert, DirectiveEffect::Inert],
            array_map(static fn(DirectiveVerdict $v): DirectiveEffect => $v->effect, $verdicts),
        );
        self::assertNull($verdicts[0]->maskedBy);
        self::assertNull($verdicts[1]->maskedBy);
    }

    /**
     * A dead directive beside a live one stays dead.
     *
     * They share a subject, so one could mask the other; the live one moves
     * the outcome on its own account, and a coalition compared against the
     * baseline would credit that movement to both and refuse to judge either.
     * The comparison is differential for exactly this reason — with the
     * neighbour already removed from both sides, what is left is what this
     * directive does.
     */
    #[Test]
    public function itStillCallsADirectiveInertWhenItsOnlyNeighbourIsTheLiveOne(): void
    {
        $class = self::subject('Widget');
        $method = self::subject('Widget', 'render');
        $executor = self::executor([['subject' => $method, 'value' => 25]]);

        $verdicts = self::audit($executor, [
            // Live: covers the method the rule reports on, and the class beside it.
            self::override(5, $class, warning: 30, error: 40, scope: ControlScope::Class_),
            self::override(5, $method, warning: 30, error: 40, scope: ControlScope::Class_),
            // Dead: covers only the class, where the rule reports nothing.
            self::override(9, $class, warning: 30, error: 40),
        ]);

        self::assertSame(DirectiveEffect::Effective, $verdicts[0]->effect, 'the live one');
        self::assertSame(DirectiveEffect::Inert, $verdicts[1]->effect, 'the dead one');
        self::assertNull($verdicts[1]->maskedBy);
    }

    /**
     * Every masker is taken out, not the first one.
     *
     * The same directive, now with two neighbours over the shared subject: one
     * that does nothing and one that does. Removing only the first would leave
     * the live one in the comparison and charge its effect to this directive,
     * which is the mistake the differential form exists to avoid — and one
     * neighbour is not enough to see it.
     */
    #[Test]
    public function itTakesEveryMaskerOutOfTheComparison(): void
    {
        $class = self::subject('Widget');
        $method = self::subject('Widget', 'render');
        $executor = self::executor([['subject' => $method, 'value' => 25]]);

        $verdicts = self::audit($executor, [
            // The directive under test, and a neighbour beside it: both cover
            // only the class, where the rule reports nothing.
            self::override(3, $class, warning: 30, error: 40),
            self::override(5, $class, warning: 30, error: 40),
            // The live one, sharing the class with both and covering the
            // method the rule does report on.
            self::override(7, $class, warning: 30, error: 40, scope: ControlScope::Class_),
            self::override(7, $method, warning: 30, error: 40, scope: ControlScope::Class_),
        ]);

        self::assertSame(
            [DirectiveEffect::Inert, DirectiveEffect::Inert, DirectiveEffect::Effective],
            array_map(static fn(DirectiveVerdict $v): DirectiveEffect => $v->effect, $verdicts),
        );
        self::assertNull($verdicts[0]->maskedBy);
        self::assertNull($verdicts[1]->maskedBy);
    }

    /**
     * The masker named is the one measured, not the one listed first.
     *
     * Two neighbours cover the subject; only the more specific one actually
     * hides this directive, and it is written last. Naming by position would
     * put a directive's name beside "masked by" on the same report where that
     * directive is called something else entirely.
     */
    #[Test]
    public function itNamesTheNeighbourThatActuallyHidesIt(): void
    {
        $method = self::subject('Widget', 'render');
        $executor = self::executor([['subject' => $method, 'value' => 25]]);

        $verdicts = self::audit($executor, [
            // Least specific, and too tight to silence anything: not a masker.
            self::override(5, $method, warning: 21, error: 40, scope: ControlScope::Class_),
            // The directive under test.
            self::override(9, $method, warning: 30, error: 40, scope: ControlScope::Callable),
            // Most specific, and silences on its own: the real masker.
            self::override(13, $method, warning: 30, error: 40, scope: ControlScope::Hook),
        ]);

        $underTest = $verdicts[1];
        self::assertSame(DirectiveUnmeasurableReason::Masked, $underTest->reason);
        self::assertSame(13, $underTest->maskedBy?->line);
    }

    /**
     * A rule that publishes no boundary cannot show one moving, so an inert
     * verdict on it cannot be told from a promise the measured value had
     * already overrun. The flag says the question was not asked.
     */
    #[Test]
    public function itMarksTheBoundaryUnobservableWhenTheRulePublishedNone(): void
    {
        $subject = self::subject('Widget', 'render');
        $executor = self::executor([['subject' => $subject, 'value' => 100]], publishesBoundary: false);

        $verdicts = self::audit($executor, [self::override(10, $subject, warning: 50, error: 60)]);

        self::assertSame(DirectiveEffect::Inert, $verdicts[0]->effect);
        self::assertFalse($verdicts[0]->boundaryObservable);
    }

    /**
     * The same rule, and a directive that does change the outcome elsewhere:
     * the verdict is what the outcome says, and the flag still reports the
     * limitation rather than hiding it behind an answer.
     */
    #[Test]
    public function itStillJudgesTheOutcomeOfARuleThatPublishesNoBoundary(): void
    {
        $class = self::subject('Widget');
        $method = self::subject('Widget', 'render');
        $executor = self::executor(
            [['subject' => $class, 'value' => 100], ['subject' => $method, 'value' => 25]],
            publishesBoundary: false,
        );

        $verdicts = self::audit($executor, [
            self::override(10, $class, warning: 50, error: 60, scope: ControlScope::Class_),
            self::override(10, $method, warning: 50, error: 60, scope: ControlScope::Class_),
        ]);

        self::assertSame(DirectiveEffect::Effective, $verdicts[0]->effect);
        self::assertFalse($verdicts[0]->boundaryObservable);
    }

    /**
     * The universe is what the rules produced, not what the report would
     * publish: a directive moving a finding the per-rule ledger drops anyway
     * is still doing something.
     */
    #[Test]
    public function itJudgesByWhatTheRulesProducedRatherThanWhatTheyPublished(): void
    {
        $subject = self::subject('Widget', 'render');
        $executor = self::executor([['subject' => $subject, 'value' => 25]], excludedFromPublished: true);

        $verdicts = self::audit($executor, [self::override(10, $subject, warning: 30, error: 40)]);

        self::assertSame(DirectiveEffect::Effective, $verdicts[0]->effect);
    }

    /**
     * The control the method rests on, at the near end: rules that do not
     * reproduce their own run cannot be asked what a directive changed.
     */
    #[Test]
    public function itRefusesEveryVerdictWhenTheFirstControlDoesNotReproduceTheRun(): void
    {
        $subject = self::subject('Widget', 'render');
        $executor = self::executor([['subject' => $subject, 'value' => 25]], driftsAtExecution: 2);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('before the sweep');

        self::audit($executor, [self::override(10, $subject, warning: 30, error: 40)]);
    }

    /**
     * And at the far end, which is the half a single control cannot give:
     * this executor reproduces the run until the sweep is over and drifts on
     * the last pass.
     *
     * That pass is the fifth execution under a narrowed sweep: baseline (the
     * test helper), before-control, the narrowed reference, one removal, then
     * after-control.
     */
    #[Test]
    public function itRefusesEveryVerdictWhenTheLastControlDoesNotReproduceTheRun(): void
    {
        $subject = self::subject('Widget', 'render');
        $executor = self::executor([['subject' => $subject, 'value' => 25]], driftsAtExecution: 5);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('after the sweep');

        self::audit($executor, [self::override(10, $subject, warning: 30, error: 40)]);
    }

    /**
     * The control has to walk the road it vouches for.
     *
     * A counterfactual executes against a **rebuilt** context; a control
     * executing against the run's own object would vouch for a different path
     * and miss a rule that tells the two apart. This executor does tell them
     * apart, so the control must refuse the sweep — and it can only notice
     * that by being rebuilt itself.
     */
    #[Test]
    public function itControlsTheRunThroughTheSamePathTheCounterfactualsTake(): void
    {
        $subject = self::subject('Widget', 'render');
        $context = self::context([self::override(10, $subject, warning: 30, error: 40)]);
        $executor = new ScriptedThresholdRuleExecution(
            self::RULE,
            [['subject' => $subject, 'value' => 25]],
            20,
            40,
            RelativePath::fromString(self::FILE),
            answersOnlyFor: $context,
        );

        $registry = new RuleOptionsRegistry();
        $registry->configureSelection(new RuleSelection());
        $audit = new ThresholdDirectiveAudit(
            self::productionUniverse(),
            new RuleSelector(new InMemoryRuleChannelRegistry()),
            $registry,
        );

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('before the sweep');

        $audit->verdicts(new ThresholdDirectiveAuditInput($context, $executor, $executor->execute($context)));
    }

    /**
     * The third control, and the one the narrowed sweep alone needs: a rule
     * that behaves differently when executed on its own than it did inside
     * the full run must refuse the sweep, naming itself.
     */
    #[Test]
    public function itRefusesTheNarrowedSweepWhenARuleBehavesDifferentlyInIsolation(): void
    {
        $subject = self::subject('Widget', 'render');
        $executor = new ScriptedThresholdRuleExecution(
            self::RULE,
            [['subject' => $subject, 'value' => 25]],
            20,
            40,
            RelativePath::fromString(self::FILE),
            driftsWhenRestricted: true,
        );

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage(self::RULE);
        $this->expectExceptionMessage('did not reproduce what it produced in the full run');

        self::audit($executor, [self::override(10, $subject, warning: 30, error: 40)]);
    }

    /**
     * The reference a counterfactual is measured against must be taken by the
     * same narrowing as the counterfactual itself — never the full baseline
     * projected onto one rule's name.
     *
     * Rule A's own directive genuinely changes nothing; a neighbour, Rule B,
     * fires in the baseline and is never touched by any directive. A
     * reference taken with the full selection would carry Rule B's finding,
     * which the narrowed counterfactual (Rule A only) never produces —
     * reading that absence as Rule A's directive removing something, and
     * reporting `Effective` where the truth is `Inert`.
     */
    #[Test]
    public function itComparesTheCounterfactualAgainstAReferenceTakenByTheSameNarrowing(): void
    {
        $ruleA = 'coupling.cbo';
        $ruleB = 'complexity.cyclomatic';
        $subjectA = self::subject('Widget', 'render');
        $subjectB = self::subject('Gadget', 'compute');
        $file = RelativePath::fromString(self::FILE);

        // Rule A: the override raises its warning to 30, but the measured
        // value never crosses either boundary — its directive is inert.
        $execA = new ScriptedThresholdRuleExecution($ruleA, [['subject' => $subjectA, 'value' => 5]], 20, 40, $file);
        // Rule B: fires under its own defaults, untouched by any directive.
        $execB = new ScriptedThresholdRuleExecution($ruleB, [['subject' => $subjectB, 'value' => 100]], 20, 40, $file);
        $executor = new MultiRuleThresholdRuleExecution([$ruleA => $execA, $ruleB => $execB]);

        $overrideA = new ThresholdOverride($ruleA, 30, 40, 10, $subjectA, ControlScope::Callable);
        $context = self::context([$overrideA]);
        $baseline = $executor->execute($context);

        $registry = new RuleOptionsRegistry();
        $registry->configureSelection(new RuleSelection());
        $audit = new ThresholdDirectiveAudit(
            self::productionUniverse(),
            new RuleSelector(new InMemoryRuleChannelRegistry()),
            $registry,
        );

        $verdicts = $audit->verdicts(new ThresholdDirectiveAuditInput($context, $executor, $baseline));

        self::assertCount(1, $verdicts, 'only rule A authored a directive');
        self::assertSame(DirectiveEffect::Inert, $verdicts[0]->effect);
    }

    /**
     * A narrow sweep and a full sweep must agree, verdict for verdict, on a
     * tree carrying several rules' directives and a mix of outcomes — the
     * control {@see DirectiveSweepScope::Full} exists for, per
     * {@see ThresholdDirectiveAudit::reference()}.
     *
     * Rule C authors no directive and fires unconditionally: it is the third
     * producer the narrow sweep must never execute for Rule B's removal, and
     * whose absence-versus-presence a `Full` sweep must not confuse for Rule
     * B's own directive doing something — Rule B's own change is genuinely
     * inert. A `Narrow` implementation that leaked Rule C into the removal
     * pass but kept its reference narrowed (or the reverse) would answer
     * `Effective` here where `Full` answers `Inert`.
     */
    #[Test]
    public function itProducesTheSameVerdictsUnderANarrowAndAFullSweepOnATreeWithSeveralRules(): void
    {
        $ruleA = 'coupling.cbo';
        $ruleB = 'complexity.cyclomatic';
        $ruleC = 'complexity.cognitive';
        $subjectA = self::subject('Widget', 'render');
        $subjectB = self::subject('Gadget', 'compute');
        $subjectC = self::subject('Thing', 'run');
        $file = RelativePath::fromString(self::FILE);

        // Rule A: removing the directive raises the finding — Effective.
        $execA = new ScriptedThresholdRuleExecution($ruleA, [['subject' => $subjectA, 'value' => 25]], 20, 40, $file);
        // Rule B: never crosses either boundary, with or without its
        // directive — genuinely Inert on its own.
        $execB = new ScriptedThresholdRuleExecution($ruleB, [['subject' => $subjectB, 'value' => 5]], 20, 40, $file);
        // Rule C: no authored directive at all, and fires unconditionally —
        // present in every removal pass a correct sweep runs unrestricted.
        $execC = new ScriptedThresholdRuleExecution($ruleC, [['subject' => $subjectC, 'value' => 100]], 20, 40, $file);
        $executor = new MultiRuleThresholdRuleExecution([$ruleA => $execA, $ruleB => $execB, $ruleC => $execC]);

        $overrideA = new ThresholdOverride($ruleA, 30, 40, 10, $subjectA, ControlScope::Callable);
        $overrideB = new ThresholdOverride($ruleB, 30, 40, 20, $subjectB, ControlScope::Callable);
        $context = self::context([$overrideA, $overrideB]);
        $baseline = $executor->execute($context);

        $registry = new RuleOptionsRegistry();
        $registry->configureSelection(new RuleSelection());
        $audit = new ThresholdDirectiveAudit(
            self::productionUniverse(),
            new RuleSelector(new InMemoryRuleChannelRegistry()),
            $registry,
        );

        $narrow = $audit->verdicts(new ThresholdDirectiveAuditInput($context, $executor, $baseline, DirectiveSweepScope::Narrow));

        // The baseline call above ran C once (executions === 1), and
        // ThresholdDirectiveAudit::sweep()'s two reproducibility controls
        // ("before"/"after") always run the whole rule set unrestricted, by
        // design, whichever scope was asked for — that is +2, unconditionally.
        // What the docblock claims is narrower: the *per-directive*
        // reference and removal passes for rule A and rule B must stay
        // restricted to their own rule and never touch C. A narrow sweep
        // that leaked those two passes into unrestricted runs would add two
        // more C executions on top (one per directive: A's and B's), landing
        // on 5 — indistinguishable from a `Full` sweep's own count. 3 is the
        // value only a genuinely narrow removal/reference pass produces.
        self::assertSame(
            3,
            $execC->executions,
            'rule C must run only for the sweep\'s own before/after reproducibility controls (unrestricted by'
            . ' design), never for rule A\'s or rule B\'s narrowed reference/removal passes',
        );

        $full = $audit->verdicts(new ThresholdDirectiveAuditInput($context, $executor, $baseline, DirectiveSweepScope::Full));

        self::assertEquals($full, $narrow);
        self::assertCount(2, $narrow);
        self::assertSame(
            [DirectiveEffect::Effective, DirectiveEffect::Inert],
            array_map(static fn(DirectiveVerdict $v): DirectiveEffect => $v->effect, $narrow),
        );
    }

    /**
     * @param list<ThresholdOverride> $overrides
     *
     * @return list<DirectiveVerdict>
     */
    private static function audit(
        ScriptedThresholdRuleExecution $executor,
        array $overrides,
        ?RuleSelection $selection = null,
    ): array {
        $registry = new RuleOptionsRegistry();
        $registry->configureSelection($selection ?? new RuleSelection());

        return self::auditWith($executor, $overrides, $registry);
    }

    /**
     * @param list<ThresholdOverride> $overrides
     *
     * @return list<DirectiveVerdict>
     */
    private static function auditWith(
        ScriptedThresholdRuleExecution $executor,
        array $overrides,
        RuleOptionsRegistry $registry,
    ): array {
        $context = self::context($overrides);

        $audit = new ThresholdDirectiveAudit(
            self::productionUniverse(),
            new RuleSelector(new InMemoryRuleChannelRegistry()),
            $registry,
        );

        return $audit->verdicts(new ThresholdDirectiveAuditInput($context, $executor, $executor->execute($context)));
    }

    /** @param list<ThresholdOverride> $overrides */
    private static function context(array $overrides): AnalysisContext
    {
        return new AnalysisContext(
            metrics: new InMemoryMetricRepository(),
            thresholdOverrides: [self::FILE => $overrides],
        );
    }

    /** @param list<array{subject: MetricSubject, value: int|float}> $measurements */
    private static function executor(
        array $measurements,
        bool $publishesBoundary = true,
        bool $excludedFromPublished = false,
        ?int $driftsAtExecution = null,
    ): ScriptedThresholdRuleExecution {
        return new ScriptedThresholdRuleExecution(
            self::RULE,
            $measurements,
            20,
            40,
            RelativePath::fromString(self::FILE),
            $publishesBoundary,
            $excludedFromPublished,
            $driftsAtExecution,
        );
    }

    private static function override(
        int $line,
        MetricSubject $subject,
        int|float $warning,
        int|float $error,
        ControlScope $scope = ControlScope::Callable,
    ): ThresholdOverride {
        return new ThresholdOverride(self::RULE, $warning, $error, $line, $subject, $scope);
    }

    private static function subject(string $class, ?string $method = null): MetricSubject
    {
        $symbol = $method === null
            ? SymbolPath::forClass('App', $class)
            : SymbolPath::forMethod('App', $class, $method);

        return MetricSubject::declaration(DeclarationPath::of(
            $symbol,
            RelativePath::fromString(self::FILE),
            DeclarationOrdinal::fromRank(0),
        ));
    }

    private static ?RuleChannelSnapshotFactoryInterface $snapshotFactory = null;

    private static function productionUniverse(): ChannelUniverseInterface
    {
        if (self::$snapshotFactory === null) {
            $universe = (new ContainerFactory())->create()->get(ChannelUniverseInterface::class);
            \assert($universe instanceof RuleChannelSnapshotFactoryInterface);
            self::$snapshotFactory = $universe;
        }

        return self::$snapshotFactory->snapshot(new ResolvedComputedMetricDefinitions([]));
    }
}
