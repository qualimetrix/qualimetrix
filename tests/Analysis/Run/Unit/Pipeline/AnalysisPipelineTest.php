<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Run\Unit\Pipeline;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\CircularDependency\Contract\CircularDependencyPreparationInterface;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Definition\ComputedMetricDefinitionCatalogInterface;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Evaluation\ComputedMetricEvaluator;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyGraphBuilderInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MeasurementAggregationInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricRepositoryFactoryInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricRepositoryInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\NamespaceTree;
use Qualimetrix\Analysis\Evidence\Measurement\Repository\InMemoryMetricRepository;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleSelector;
use Qualimetrix\Analysis\Finding\Contract\RuleExclusionStats;
use Qualimetrix\Analysis\Finding\Contract\RuleExecutionInterface;
use Qualimetrix\Analysis\Finding\Contract\RuleExecutionResult;
use Qualimetrix\Analysis\Finding\Rule\InMemoryRuleChannelRegistry;
use Qualimetrix\Analysis\Finding\RuleConfiguration\RuleOptionsRegistry;
use Qualimetrix\Analysis\Policy\Architecture\Contract\LayerPolicyPreparationInterface;
use Qualimetrix\Analysis\Policy\Inline\Contract\Directive\InlineDirectivePolicyInterface;
use Qualimetrix\Analysis\Run\Contract\Collection\CollectionOrchestratorInterface;
use Qualimetrix\Analysis\Run\Contract\Collection\CollectionPhaseOutput;
use Qualimetrix\Analysis\Run\Contract\Configuration\GeneratedFilePolicy;
use Qualimetrix\Analysis\Run\Contract\Configuration\RunConfiguration;
use Qualimetrix\Analysis\Run\Contract\Discovery\FileDiscoveryInterface;
use Qualimetrix\Analysis\Run\Discovery\AnalysisFileDiscovery;
use Qualimetrix\Analysis\Run\Discovery\GeneratedFileFilter;
use Qualimetrix\Analysis\Run\FileSetInspection\FileSetInspectionComposite;
use Qualimetrix\Analysis\Run\FileSetInspection\RuleSelectorProducerGate;
use Qualimetrix\Analysis\Run\Pipeline\AnalysisPipeline;
use Qualimetrix\Analysis\Run\RuleProducerPreparation;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Core\Path\PathFactory;
use Qualimetrix\Core\Profiler\Contract\ProfilerInterface;
use Qualimetrix\Tests\Analysis\Evidence\CircularDependency\Support\AdjacencyGraphBuilder;
use SplFileInfo;

#[CoversClass(AnalysisPipeline::class)]
final class AnalysisPipelineTest extends TestCase
{
    #[Test]
    public function itRunsWithAnExplicitOwnerConfigurationAndReturnsMeasuredCoverage(): void
    {
        $root = AbsolutePath::fromString(\dirname(__DIR__, 5));
        $file = new SplFileInfo(__FILE__);
        $relative = PathFactory::bestEffortRelative(__FILE__, $root);

        $discovery = self::createStub(FileDiscoveryInterface::class);
        $discovery->method('discover')->willReturn([$file]);

        $collection = $this->createMock(CollectionOrchestratorInterface::class);
        $collection->expects(self::once())->method('collect')
            ->with([$file], self::isInstanceOf(MetricRepositoryInterface::class), $root)
            ->willReturn(new CollectionPhaseOutput([$relative], []));

        $pipeline = $this->pipeline($discovery, $collection);
        $configuration = new RunConfiguration(
            [$root],
            ['vendor'],
            $root,
            GeneratedFilePolicy::Include,
        );

        $result = $pipeline->analyze($configuration);

        self::assertSame([$relative], $result->coverage->analyzedFiles);
        self::assertSame([], $result->findings);
    }

    #[Test]
    public function itUsesTheInvocationDiscoveryOverrideWithoutMutatingTheDefault(): void
    {
        $root = AbsolutePath::fromString(\dirname(__DIR__, 5));
        $default = $this->createMock(FileDiscoveryInterface::class);
        $default->expects(self::never())->method('discover');
        $override = self::createStub(FileDiscoveryInterface::class);
        $override->method('discover')->willReturn([]);

        $collection = self::createStub(CollectionOrchestratorInterface::class);
        $collection->method('collect')->willReturn(new CollectionPhaseOutput([], []));

        $result = $this->pipeline($default, $collection)->analyze(
            new RunConfiguration([$root], [], $root, GeneratedFilePolicy::Include),
            $override,
        );

        self::assertSame([], $result->coverage->analyzedFiles);
    }

    #[Test]
    public function itUsesTheProjectRootFromEachRunInTheSameProcess(): void
    {
        $firstRoot = AbsolutePath::fromString(\dirname(__DIR__, 5));
        $secondRoot = AbsolutePath::fromString(sys_get_temp_dir());
        $discovery = self::createStub(FileDiscoveryInterface::class);
        $discovery->method('discover')->willReturn([]);

        $seenRoots = [];
        $collection = self::createStub(CollectionOrchestratorInterface::class);
        $collection->method('collect')->willReturnCallback(
            static function (array $files, MetricRepositoryInterface $repository, AbsolutePath $root) use (&$seenRoots): CollectionPhaseOutput {
                $seenRoots[] = $root->value();

                return new CollectionPhaseOutput([], []);
            },
        );
        $pipeline = $this->pipeline($discovery, $collection);

        $pipeline->analyze(new RunConfiguration([$firstRoot], [], $firstRoot, GeneratedFilePolicy::Include));
        $pipeline->analyze(new RunConfiguration([$secondRoot], [], $secondRoot, GeneratedFilePolicy::Include));

        self::assertSame([$firstRoot->value(), $secondRoot->value()], $seenRoots);
    }

    private function pipeline(
        FileDiscoveryInterface $discovery,
        CollectionOrchestratorInterface $collection,
    ): AnalysisPipeline {
        $profiler = self::createStub(ProfilerInterface::class);
        $ruleConfiguration = new RuleOptionsRegistry();
        $selector = new RuleSelector(new InMemoryRuleChannelRegistry());
        $fileSetInspection = new FileSetInspectionComposite(
            [],
            new RuleSelectorProducerGate($selector),
            $profiler,
        );
        $layerPolicy = self::createStub(LayerPolicyPreparationInterface::class);
        $circular = self::createStub(CircularDependencyPreparationInterface::class);
        $preparation = new RuleProducerPreparation(
            $layerPolicy,
            $circular,
            self::createStub(InlineDirectivePolicyInterface::class),
            $fileSetInspection,
            $selector,
            $ruleConfiguration,
        );

        $aggregation = self::createStub(MeasurementAggregationInterface::class);
        $aggregation->method('aggregate')->willReturn(new NamespaceTree([]));

        $catalog = self::createStub(ComputedMetricDefinitionCatalogInterface::class);
        $catalog->method('all')->willReturn([]);
        $computed = new ComputedMetricEvaluator($catalog, $profiler);

        $graphBuilder = self::createStub(DependencyGraphBuilderInterface::class);
        $graphBuilder->method('build')->willReturn(AdjacencyGraphBuilder::empty());

        $repository = new InMemoryMetricRepository();
        $repositoryFactory = self::createStub(MetricRepositoryFactoryInterface::class);
        $repositoryFactory->method('create')->willReturn($repository);

        $rules = self::createStub(RuleExecutionInterface::class);
        $rules->method('execute')->willReturn(new RuleExecutionResult([], [], new RuleExclusionStats()));
        $rules->method('allRules')->willReturn([]);

        return new AnalysisPipeline(
            new AnalysisFileDiscovery($discovery, new GeneratedFileFilter()),
            $collection,
            $rules,
            $preparation,
            $aggregation,
            $computed,
            $graphBuilder,
            $repositoryFactory,
            $profiler,
        );
    }
}
