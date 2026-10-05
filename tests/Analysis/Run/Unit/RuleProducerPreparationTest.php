<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Run\Unit;

use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\CircularDependency\CircularDependencyOptions;
use Qualimetrix\Analysis\Evidence\CircularDependency\CircularDependencyRule;
use Qualimetrix\Analysis\Evidence\CircularDependency\Contract\CircularDependencyPreparationInterface;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Definition\ResolvedComputedMetricDefinitions;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyGraphInterface;
use Qualimetrix\Analysis\Evidence\Duplication\CodeDuplicationOptions;
use Qualimetrix\Analysis\Evidence\Duplication\CodeDuplicationRule;
use Qualimetrix\Analysis\Evidence\Measurement\Repository\InMemoryMetricRepository;
use Qualimetrix\Analysis\Finding\Contract\Configuration\FindingConfiguration;
use Qualimetrix\Analysis\Finding\Contract\LevelActivity;
use Qualimetrix\Analysis\Finding\Contract\Rule\AnalysisContext;
use Qualimetrix\Analysis\Finding\Contract\RuleExclusionStats;
use Qualimetrix\Analysis\Finding\Contract\RuleExecutionInterface;
use Qualimetrix\Analysis\Finding\Contract\RuleExecutionResult;
use Qualimetrix\Analysis\Finding\Contract\RuleMetadata;
use Qualimetrix\Analysis\Finding\RuleConfiguration\RuleOptionsRegistry;
use Qualimetrix\Analysis\Policy\Architecture\Contract\LayerPolicyPreparationInterface;
use Qualimetrix\Analysis\Policy\Architecture\LayerViolation\LayerDeclarationValidator;
use Qualimetrix\Analysis\Policy\Architecture\LayerViolation\LayerViolationOptions;
use Qualimetrix\Analysis\Policy\Architecture\LayerViolation\LayerViolationRule;
use Qualimetrix\Analysis\Policy\Architecture\LayerViolation\UnassignedClassOptions;
use Qualimetrix\Analysis\Policy\Architecture\LayerViolation\UnassignedClassRule;
use Qualimetrix\Analysis\Policy\Inline\Contract\Directive\DirectiveSweepScope;
use Qualimetrix\Analysis\Policy\Inline\Contract\Directive\InlineDirectivePolicyInterface;
use Qualimetrix\Analysis\Policy\Inline\Contract\Directive\ThresholdDirectiveAuditInput;
use Qualimetrix\Analysis\Policy\Inline\Contract\Directive\ThresholdDirectiveAuditInterface;
use Qualimetrix\Analysis\Run\Contract\FileSetInspectionParticipantInterface;
use Qualimetrix\Analysis\Run\FileSetInspection\FileSetInspectionComposite;
use Qualimetrix\Analysis\Run\FileSetInspection\RuleSelectorProducerGate;
use Qualimetrix\Analysis\Run\InlineDirectiveRun;
use Qualimetrix\Analysis\Run\RuleProducerPreparation;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Core\Profiler\Contract\ProfilerInterface;
use Qualimetrix\Infrastructure\Rule\ChannelUniverse;
use Qualimetrix\Tests\Analysis\Finding\Support\ResolvedOptionsFixture;

#[CoversClass(RuleProducerPreparation::class)]
#[CoversClass(InlineDirectiveRun::class)]
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
            disabled: [CircularDependencyPreparationInterface::PRODUCER_RULE_NAME],
            participants: [$participant],
        );
        $preparation->prepareCircularDependencies(
            self::createStub(DependencyGraphInterface::class),
            self::createStub(ProfilerInterface::class),
        );
        $preparation->inspectFiles([], AbsolutePath::fromString('/project'), []);

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
            disabled: LayerPolicyPreparationInterface::PRODUCER_RULE_NAMES,
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
            only: $only,
            ruleOptions: [LayerPolicyPreparationInterface::UNASSIGNED_CLASS_DIAGNOSTIC_NAME => ['mode' => 'warn']],
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
            disabled: [CircularDependencyPreparationInterface::PRODUCER_RULE_NAME],
        )->prepareCircularDependencies(
            self::createStub(DependencyGraphInterface::class),
            $profiler,
        );
    }

    /**
     * A rule switched off by its own options produced nothing and was prepared
     * in full anyway: the gate read the selectors alone, so `--disable-rule`
     * skipped the traversal and `rules: {…: {enabled: false}}` paid for it.
     *
     * @param array<string, mixed> $ruleOptions
     */
    #[Test]
    #[TestWith([[CircularDependencyPreparationInterface::PRODUCER_RULE_NAME => ['enabled' => false]]])]
    #[TestWith([[CircularDependencyPreparationInterface::PRODUCER_RULE_NAME => false]])]
    public function itSkipsCircularDependencyPreparationWhenItsOwnOptionsSwitchTheRuleOff(array $ruleOptions): void
    {
        $circular = $this->createMock(CircularDependencyPreparationInterface::class);
        $circular->expects(self::never())->method('prepare');
        $circular->expects(self::once())->method('reset');
        $profiler = $this->createMock(ProfilerInterface::class);
        $profiler->expects(self::never())->method('start');

        $this->preparation(circular: $circular, ruleOptions: $ruleOptions)->prepareCircularDependencies(
            self::createStub(DependencyGraphInterface::class),
            $profiler,
        );
    }

    /**
     * @param array<string, mixed> $ruleOptions
     */
    #[Test]
    #[TestWith([[
        LayerPolicyPreparationInterface::PRODUCER_RULE_NAME => ['enabled' => false],
        LayerPolicyPreparationInterface::UNASSIGNED_CLASS_DIAGNOSTIC_NAME => false,
    ]])]
    #[TestWith([[
        LayerPolicyPreparationInterface::PRODUCER_RULE_NAME => false,
        LayerPolicyPreparationInterface::UNASSIGNED_CLASS_DIAGNOSTIC_NAME => ['enabled' => false],
    ]])]
    public function itSkipsArchitecturePreparationWhenBothProducersAreSwitchedOffByTheirOptions(array $ruleOptions): void
    {
        $architecture = $this->createMock(LayerPolicyPreparationInterface::class);
        $architecture->expects(self::never())->method('prepare');
        $architecture->expects(self::once())->method('reset');
        $profiler = $this->createMock(ProfilerInterface::class);
        $profiler->expects(self::never())->method('start');

        $this->preparation(architecture: $architecture, ruleOptions: $ruleOptions)->prepareArchitecture(
            self::createStub(DependencyGraphInterface::class),
            [],
            $profiler,
        );
    }

    #[Test]
    public function itResetsArchitecturePolicyWhenLayerViolationIsOffAndUnassignedClassIsMuted(): void
    {
        $architecture = $this->createMock(LayerPolicyPreparationInterface::class);
        $architecture->expects(self::never())->method('prepare');
        $architecture->expects(self::once())->method('reset');

        $this->preparation(
            architecture: $architecture,
            ruleOptions: [LayerPolicyPreparationInterface::PRODUCER_RULE_NAME => ['enabled' => false]],
        )->prepareArchitecture(
            self::createStub(DependencyGraphInterface::class),
            [],
            self::createStub(ProfilerInterface::class),
        );
    }

    #[Test]
    public function itSkipsFileSetInspectionWhenTheParticipantsOwnOptionsSwitchTheProducerOff(): void
    {
        $participant = new class implements FileSetInspectionParticipantInterface {
            public int $resetCalls = 0;
            public int $inspectCalls = 0;

            public static function participantId(): string
            {
                return 'options-gated-participant';
            }

            public static function producerRuleName(): string
            {
                return 'duplication.clone';
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

        $this->preparation(
            participants: [$participant],
            ruleOptions: ['duplication.clone' => ['enabled' => false]],
        )->inspectFiles([], AbsolutePath::fromString('/project'), []);

        self::assertSame(1, $participant->resetCalls);
        self::assertSame(0, $participant->inspectCalls);
    }

    #[Test]
    public function itPreparesTheArchitectureProducerWhenOnlyADiagnosticChannelIsSelected(): void
    {
        $architecture = $this->createMock(LayerPolicyPreparationInterface::class);
        $architecture->expects(self::once())->method('prepare');
        $this->preparation(
            architecture: $architecture,
            only: ['architecture.coverage-gap'],
        )->prepareArchitecture(
            self::createStub(DependencyGraphInterface::class),
            [],
            self::createStub(ProfilerInterface::class),
        );
    }

    /**
     * `InlineDirectiveRun::verdicts()` is the last hop of a value that also lands,
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
        $baseline = new RuleExecutionResult([], [], new RuleExclusionStats(), LevelActivity::empty());

        (new InlineDirectiveRun(self::createStub(InlineDirectivePolicyInterface::class), $spy))
            ->verdicts([], LevelActivity::empty(), $context, $executor, $baseline, $sweep);

        self::assertSame($sweep, $spy->received?->sweep);
    }

    /**
     * @param (LayerPolicyPreparationInterface&MockObject)|null $architecture
     * @param (CircularDependencyPreparationInterface&MockObject)|null $circular
     * @param list<string> $only
     * @param list<string> $disabled
     * @param list<FileSetInspectionParticipantInterface> $participants
     * @param array<string, mixed> $ruleOptions
     */
    private function preparation(
        ?LayerPolicyPreparationInterface $architecture = null,
        ?CircularDependencyPreparationInterface $circular = null,
        array $only = [],
        array $disabled = [],
        array $participants = [],
        array $ruleOptions = [],
    ): RuleProducerPreparation {
        $metadata = [
            new RuleMetadata(LayerPolicyPreparationInterface::PRODUCER_RULE_NAME, LayerViolationOptions::class, '', [], false),
            new RuleMetadata(LayerPolicyPreparationInterface::UNASSIGNED_CLASS_DIAGNOSTIC_NAME, UnassignedClassOptions::class, '', [], false),
            new RuleMetadata(CircularDependencyPreparationInterface::PRODUCER_RULE_NAME, CircularDependencyOptions::class, '', [], false),
            new RuleMetadata('duplication.clone', CodeDuplicationOptions::class, '', [], false),
        ];
        $channelsByProducer = [
            LayerPolicyPreparationInterface::PRODUCER_RULE_NAME => [
                ...LayerViolationRule::channelDeclarations(),
                ...LayerDeclarationValidator::channelDeclarations(),
            ],
            LayerPolicyPreparationInterface::UNASSIGNED_CLASS_DIAGNOSTIC_NAME => UnassignedClassRule::channelDeclarations(),
            CircularDependencyPreparationInterface::PRODUCER_RULE_NAME => CircularDependencyRule::channelDeclarations(),
            'duplication.clone' => CodeDuplicationRule::channelDeclarations(),
        ];
        $declarations = [];
        $channelKeys = [];
        $support = [];
        foreach ($channelsByProducer as $producer => $channels) {
            $declarations = [...$declarations, ...$channels];
            $channelKeys[$producer] = array_keys($channels);
            $support[$producer] = false;
        }
        $universe = new ChannelUniverse($declarations, $channelKeys, $support, new ResolvedComputedMetricDefinitions([]), ...self::unusedReachPorts());
        $document = ResolvedOptionsFixture::document([['source' => 'config', 'values' => [
            'rules' => $ruleOptions,
            'only_rules' => $only,
            'disabled_rules' => $disabled,
        ]]], AbsolutePath::fromString('/project'), $metadata);
        $registry = new RuleOptionsRegistry();
        $registry->replace(ResolvedOptionsFixture::ready(FindingConfiguration::fromDocument($document), $metadata, channels: $universe));

        $producerGate = new RuleSelectorProducerGate($registry);

        return new RuleProducerPreparation(
            $architecture ?? self::createStub(LayerPolicyPreparationInterface::class),
            $circular ?? self::createStub(CircularDependencyPreparationInterface::class),
            new FileSetInspectionComposite(
                $participants,
                $producerGate,
                self::createStub(ProfilerInterface::class),
            ),
            $producerGate,
        );
    }

    /** @return array{\Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricReachCatalogInterface, \Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Definition\ComputedMetricReachInterface} */
    private static function unusedReachPorts(): array
    {
        return [
            new class implements \Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricReachCatalogInterface {
                public function metricReach(string $metricKey): \Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricReach
                {
                    throw new LogicException('This fixture does not query measured-metric reach.');
                }
            },
            new class implements \Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Definition\ComputedMetricReachInterface {
                public function reachAt(
                    string $metricName,
                    \Qualimetrix\Core\Symbol\SymbolLevel $level,
                    \Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Definition\ComputedMetricDefinitionCatalogInterface $definitions,
                ): \Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricReach {
                    throw new LogicException('This fixture does not query computed-metric reach.');
                }
            },
        ];
    }
}
