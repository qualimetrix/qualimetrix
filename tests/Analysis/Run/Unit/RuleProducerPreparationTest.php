<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Run\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\CircularDependency\Contract\CircularDependencyPreparationInterface;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyGraphInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Repository\InMemoryMetricRepository;
use Qualimetrix\Analysis\Finding\Contract\FindingChannel;
use Qualimetrix\Analysis\Finding\Contract\Rule\AnalysisContext;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleChannelRegistryInterface;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleSelector;
use Qualimetrix\Analysis\Finding\Contract\RuleExclusionStats;
use Qualimetrix\Analysis\Finding\Contract\RuleExecutionInterface;
use Qualimetrix\Analysis\Finding\Contract\RuleExecutionResult;
use Qualimetrix\Analysis\Finding\Contract\RuleSelection;
use Qualimetrix\Analysis\Finding\Rule\InMemoryRuleChannelRegistry;
use Qualimetrix\Analysis\Finding\RuleConfiguration\RuleOptionsRegistry;
use Qualimetrix\Analysis\Policy\Architecture\Contract\LayerPolicyPreparationInterface;
use Qualimetrix\Analysis\Policy\Inline\Contract\Directive\DirectiveSweepScope;
use Qualimetrix\Analysis\Policy\Inline\Contract\Directive\InlineDirectivePolicyInterface;
use Qualimetrix\Analysis\Policy\Inline\Contract\Directive\ThresholdDirectiveAuditInput;
use Qualimetrix\Analysis\Policy\Inline\Contract\Directive\ThresholdDirectiveAuditInterface;
use Qualimetrix\Analysis\Run\Contract\FileSetInspectionParticipantInterface;
use Qualimetrix\Analysis\Run\FileSetInspection\FileSetInspectionComposite;
use Qualimetrix\Analysis\Run\FileSetInspection\RuleSelectorProducerGate;
use Qualimetrix\Analysis\Run\RuleProducerPreparation;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Core\Profiler\Contract\ProfilerInterface;

#[CoversClass(RuleProducerPreparation::class)]
final class RuleProducerPreparationTest extends TestCase
{
    #[Test]
    public function itSkipsCircularDependencyDetectionWhenRuleDisabled(): void
    {
        $circular = $this->createMock(CircularDependencyPreparationInterface::class);
        $circular->expects(self::never())->method('prepare');
        $circular->expects(self::once())->method('reset');
        $participant = new class implements FileSetInspectionParticipantInterface {
            public int $resetCalls = 0;
            public int $inspectCalls = 0;

            public static function participantId(): string
            {
                return 'circular-test-participant';
            }

            public static function producerRuleName(): string
            {
                return CircularDependencyPreparationInterface::PRODUCER_RULE_NAME;
            }

            public function resetForRun(): void
            {
                ++$this->resetCalls;
            }

            public function inspect(array $eligibleFiles, AbsolutePath $projectRoot): void
            {
                ++$this->inspectCalls;
            }
        };

        $preparation = $this->preparation(
            circular: $circular,
            selection: new RuleSelection(disabled: [CircularDependencyPreparationInterface::PRODUCER_RULE_NAME]),
            participants: [$participant],
        );
        $preparation->prepareCircularDependencies(
            self::createStub(DependencyGraphInterface::class),
            self::createStub(ProfilerInterface::class),
        );
        $preparation->inspectFiles([], AbsolutePath::fromString('/project'));

        self::assertSame(1, $participant->resetCalls);
        self::assertSame(0, $participant->inspectCalls);
    }

    /**
     * Every producer that reads the policy has to be off, not just the first
     * one. Asking about one of two is exactly the bug the split introduced:
     * `--only-rule=architecture.unassigned-class` left the policy unprepared
     * and the rule reached an unprepared collector.
     */
    #[Test]
    public function itResetsArchitecturePreparationWithoutDoingWorkWhenEveryLayerPolicyProducerIsDisabled(): void
    {
        $architecture = $this->createMock(LayerPolicyPreparationInterface::class);
        $architecture->expects(self::never())->method('prepare');
        $architecture->expects(self::once())->method('reset');
        $profiler = $this->createMock(ProfilerInterface::class);
        $profiler->expects(self::never())->method('start');

        $this->preparation(
            architecture: $architecture,
            selection: new RuleSelection(disabled: LayerPolicyPreparationInterface::PRODUCER_RULE_NAMES),
        )->prepareArchitecture(
            self::createStub(DependencyGraphInterface::class),
            [],
            $profiler,
        );
    }

    /**
     * @param list<string> $only
     */
    #[Test]
    #[TestWith([[LayerPolicyPreparationInterface::PRODUCER_RULE_NAME]])]
    #[TestWith([[LayerPolicyPreparationInterface::UNASSIGNED_CLASS_DIAGNOSTIC_NAME]])]
    public function itPreparesArchitecturePolicyForEitherOfItsProducersAlone(array $only): void
    {
        $graph = self::createStub(DependencyGraphInterface::class);
        $architecture = $this->createMock(LayerPolicyPreparationInterface::class);
        $architecture->expects(self::once())->method('prepare')->with($graph, []);
        $architecture->expects(self::never())->method('reset');

        $this->preparation(
            architecture: $architecture,
            selection: new RuleSelection(only: $only),
        )->prepareArchitecture($graph, [], self::createStub(ProfilerInterface::class));
    }

    #[Test]
    public function itPreparesArchitecturePolicyWhenLayerViolationRuleIsEnabled(): void
    {
        $graph = self::createStub(DependencyGraphInterface::class);
        $architecture = $this->createMock(LayerPolicyPreparationInterface::class);
        $architecture->expects(self::once())->method('prepare')->with($graph, []);
        $architecture->expects(self::never())->method('reset');
        $profiler = $this->createMock(ProfilerInterface::class);
        $profiler->expects(self::once())->method('start')->with('architecture-prepare', 'pipeline');
        $profiler->expects(self::once())->method('stop')->with('architecture-prepare');

        $this->preparation(architecture: $architecture)->prepareArchitecture($graph, [], $profiler);
    }

    #[Test]
    public function itResetsCircularDependencyPreparationWithoutDoingWorkWhenRuleIsDisabled(): void
    {
        $circular = $this->createMock(CircularDependencyPreparationInterface::class);
        $circular->expects(self::never())->method('prepare');
        $circular->expects(self::once())->method('reset');
        $profiler = $this->createMock(ProfilerInterface::class);
        $profiler->expects(self::never())->method('start');

        $this->preparation(
            circular: $circular,
            selection: new RuleSelection(disabled: [CircularDependencyPreparationInterface::PRODUCER_RULE_NAME]),
        )->prepareCircularDependencies(
            self::createStub(DependencyGraphInterface::class),
            $profiler,
        );
    }

    #[Test]
    public function itPreparesTheArchitectureProducerWhenOnlyADiagnosticChannelIsSelected(): void
    {
        $architecture = $this->createMock(LayerPolicyPreparationInterface::class);
        $architecture->expects(self::once())->method('prepare');
        $channels = new class implements RuleChannelRegistryInterface {
            public function channelsProducedBy(string $producerRuleName): array
            {
                return $producerRuleName === LayerPolicyPreparationInterface::PRODUCER_RULE_NAME
                    ? [new FindingChannel('architecture.coverage')]
                    : [];
            }
        };

        $this->preparation(
            architecture: $architecture,
            selector: new RuleSelector($channels),
            selection: new RuleSelection(only: ['architecture.coverage']),
        )->prepareArchitecture(
            self::createStub(DependencyGraphInterface::class),
            [],
            self::createStub(ProfilerInterface::class),
        );
    }

    /**
     * `auditThresholdDirectives()` is the last hop of a value that also lands,
     * independently, in `DirectiveAuditReport::$sweep`
     * ({@see \Qualimetrix\Analysis\Run\Pipeline\AnalysisPipeline::auditDirectives()}).
     * A mutation that hardcodes the scope passed into the audit — while
     * leaving the report field alone — would keep that field correct and
     * every verdict self-consistent, so nothing downstream would catch it.
     * This spies on the actual {@see ThresholdDirectiveAuditInput} the
     * interface receives, which a `createStub()` double (unable to observe
     * its own arguments) cannot do.
     */
    #[Test]
    #[TestWith([DirectiveSweepScope::Narrow])]
    #[TestWith([DirectiveSweepScope::Full])]
    public function itPassesTheRequestedSweepScopeThroughToTheThresholdAudit(DirectiveSweepScope $sweep): void
    {
        $spy = new class implements ThresholdDirectiveAuditInterface {
            public ?ThresholdDirectiveAuditInput $received = null;

            public function verdicts(ThresholdDirectiveAuditInput $input): array
            {
                $this->received = $input;

                return [];
            }
        };

        $context = new AnalysisContext(metrics: new InMemoryMetricRepository());
        $executor = self::createStub(RuleExecutionInterface::class);
        $baseline = new RuleExecutionResult([], [], new RuleExclusionStats());

        $this->preparation(thresholdAudit: $spy)
            ->auditThresholdDirectives($context, $executor, $baseline, $sweep);

        self::assertSame($sweep, $spy->received?->sweep);
    }

    /**
     * @param (LayerPolicyPreparationInterface&MockObject)|null $architecture
     * @param (CircularDependencyPreparationInterface&MockObject)|null $circular
     * @param list<FileSetInspectionParticipantInterface> $participants
     */
    private function preparation(
        ?LayerPolicyPreparationInterface $architecture = null,
        ?CircularDependencyPreparationInterface $circular = null,
        ?RuleSelector $selector = null,
        ?RuleSelection $selection = null,
        array $participants = [],
        ?ThresholdDirectiveAuditInterface $thresholdAudit = null,
    ): RuleProducerPreparation {
        $selector ??= new RuleSelector(new InMemoryRuleChannelRegistry());
        $registry = new RuleOptionsRegistry();
        $registry->configureSelection($selection ?? new RuleSelection());

        return new RuleProducerPreparation(
            $architecture ?? self::createStub(LayerPolicyPreparationInterface::class),
            $circular ?? self::createStub(CircularDependencyPreparationInterface::class),
            self::createStub(InlineDirectivePolicyInterface::class),
            $thresholdAudit ?? self::createStub(ThresholdDirectiveAuditInterface::class),
            new FileSetInspectionComposite(
                $participants,
                new RuleSelectorProducerGate($selector),
                self::createStub(ProfilerInterface::class),
            ),
            $selector,
            $registry,
        );
    }
}
