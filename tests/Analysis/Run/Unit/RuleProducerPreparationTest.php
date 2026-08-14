<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Run\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\CircularDependency\Contract\CircularDependencyPreparationInterface;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyGraphInterface;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleChannelRegistryInterface;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleSelector;
use Qualimetrix\Analysis\Finding\Contract\RuleSelection;
use Qualimetrix\Analysis\Finding\Contract\ViolationChannel;
use Qualimetrix\Analysis\Finding\Rule\InMemoryRuleChannelRegistry;
use Qualimetrix\Analysis\Finding\RuleConfiguration\RuleOptionsRegistry;
use Qualimetrix\Analysis\Policy\Architecture\Contract\LayerPolicyPreparationInterface;
use Qualimetrix\Analysis\Run\Contract\FileSetInspectionParticipantInterface;
use Qualimetrix\Analysis\Run\FileSetInspection\FileSetInspectionComposite;
use Qualimetrix\Analysis\Run\FileSetInspection\RuleSelectorProducerGate;
use Qualimetrix\Analysis\Run\RuleProducerPreparation;
use Qualimetrix\Core\Profiler\ProfilerInterface;

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

            public function inspect(array $eligibleFiles): void
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
        $preparation->inspectFiles([]);

        self::assertSame(1, $participant->resetCalls);
        self::assertSame(0, $participant->inspectCalls);
    }

    #[Test]
    public function itResetsArchitecturePreparationWithoutDoingWorkWhenLayerViolationRuleIsDisabled(): void
    {
        $architecture = $this->createMock(LayerPolicyPreparationInterface::class);
        $architecture->expects(self::never())->method('prepare');
        $architecture->expects(self::once())->method('reset');
        $profiler = $this->createMock(ProfilerInterface::class);
        $profiler->expects(self::never())->method('start');

        $this->preparation(
            architecture: $architecture,
            selection: new RuleSelection(disabled: [LayerPolicyPreparationInterface::PRODUCER_RULE_NAME]),
        )->prepareArchitecture(
            self::createStub(DependencyGraphInterface::class),
            [],
            $profiler,
        );
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
                    ? [new ViolationChannel('architecture.coverage', 'architecture.coverage')]
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
    ): RuleProducerPreparation {
        $selector ??= new RuleSelector(new InMemoryRuleChannelRegistry());
        $registry = new RuleOptionsRegistry();
        $registry->configureSelection($selection ?? new RuleSelection());

        return new RuleProducerPreparation(
            $architecture ?? self::createStub(LayerPolicyPreparationInterface::class),
            $circular ?? self::createStub(CircularDependencyPreparationInterface::class),
            new FileSetInspectionComposite($participants, new RuleSelectorProducerGate($selector)),
            $selector,
            $registry,
        );
    }
}
