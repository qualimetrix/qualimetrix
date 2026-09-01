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
use Qualimetrix\Analysis\Finding\Contract\Control\ControlScope;
use Qualimetrix\Analysis\Finding\Contract\Rule\AnalysisContext;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleSelector;
use Qualimetrix\Analysis\Finding\Contract\RuleSelection;
use Qualimetrix\Analysis\Finding\Contract\Threshold\ThresholdOverride;
use Qualimetrix\Analysis\Finding\Rule\InMemoryRuleChannelRegistry;
use Qualimetrix\Analysis\Finding\RuleConfiguration\RuleOptionsRegistry;
use Qualimetrix\Analysis\Policy\Inline\Contract\Directive\ThresholdDirectiveAuditInput;
use Qualimetrix\Analysis\Policy\Inline\Directive\DirectiveEffect;
use Qualimetrix\Analysis\Policy\Inline\Directive\DirectiveUnmeasurableReason;
use Qualimetrix\Analysis\Policy\Inline\Directive\DirectiveVerdict;
use Qualimetrix\Analysis\Policy\Inline\Directive\ThresholdDirectiveAudit;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\DeclarationOrdinal;
use Qualimetrix\Core\Symbol\DeclarationPath;
use Qualimetrix\Core\Symbol\MetricSubject;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Infrastructure\DependencyInjection\ContainerFactory;
use Qualimetrix\Infrastructure\Rule\Contract\RuleChannelSnapshotFactoryInterface;
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
        self::assertSame(4, $executor->executions, 'the run, one removal, and the two controls around it');
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
     */
    #[Test]
    public function itRefusesEveryVerdictWhenTheLastControlDoesNotReproduceTheRun(): void
    {
        $subject = self::subject('Widget', 'render');
        $executor = self::executor([['subject' => $subject, 'value' => 25]], driftsAtExecution: 4);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('after the sweep');

        self::audit($executor, [self::override(10, $subject, warning: 30, error: 40)]);
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

        $context = new AnalysisContext(
            metrics: new InMemoryMetricRepository(),
            thresholdOverrides: [self::FILE => $overrides],
        );

        $audit = new ThresholdDirectiveAudit(
            self::productionUniverse(),
            new RuleSelector(new InMemoryRuleChannelRegistry()),
            $registry,
        );

        return $audit->verdicts(new ThresholdDirectiveAuditInput($context, $executor, $executor->execute($context)));
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
