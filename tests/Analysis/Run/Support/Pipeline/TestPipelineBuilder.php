<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Run\Support\Pipeline;

use LogicException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Qualimetrix\Analysis\Evidence\CircularDependency\Contract\CircularDependencyPreparationInterface;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Definition\ResolvedComputedMetricDefinitions;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Evaluation\ComputedMetricEvaluator;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyGraphBuilderInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MeasurementAggregationInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricRepositoryFactoryInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Repository\DefaultMetricRepositoryFactory;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleSelector;
use Qualimetrix\Analysis\Finding\Contract\RuleConfigurationInterface;
use Qualimetrix\Analysis\Finding\Contract\RuleExecutionInterface;
use Qualimetrix\Analysis\Finding\Rule\InMemoryRuleChannelRegistry;
use Qualimetrix\Analysis\Finding\RuleConfiguration\RuleOptionsRegistry;
use Qualimetrix\Analysis\Policy\Architecture\ArchitecturePolicy;
use Qualimetrix\Analysis\Policy\Architecture\Configuration\ArchitectureConfiguration;
use Qualimetrix\Analysis\Policy\Architecture\Contract\LayerPolicyPreparationInterface;
use Qualimetrix\Analysis\Policy\Inline\Contract\Directive\InlineDirectivePolicyInterface;
use Qualimetrix\Analysis\Policy\Inline\Contract\Directive\ThresholdDirectiveAuditInput;
use Qualimetrix\Analysis\Policy\Inline\Contract\Directive\ThresholdDirectiveAuditInterface;
use Qualimetrix\Analysis\Policy\Inline\Directive\DirectiveUsage;
use Qualimetrix\Analysis\Policy\Inline\Directive\InlineDirectivePolicy;
use Qualimetrix\Analysis\Run\Contract\Collection\CollectionOrchestratorInterface;
use Qualimetrix\Analysis\Run\Contract\Discovery\FileDiscoveryInterface;
use Qualimetrix\Analysis\Run\Discovery\AnalysisFileDiscovery;
use Qualimetrix\Analysis\Run\Discovery\GeneratedFileFilter;
use Qualimetrix\Analysis\Run\FileSetInspection\FileSetInspectionComposite;
use Qualimetrix\Analysis\Run\Pipeline\AnalysisPipeline;
use Qualimetrix\Analysis\Run\RuleProducerPreparation;
use Qualimetrix\Core\Profiler\Contract\ProfilerInterface;
use Qualimetrix\Infrastructure\Rule\ChannelUniverse;
use Qualimetrix\Tests\Analysis\Evidence\CircularDependency\Support\AdjacencyGraphBuilder;

/**
 * Fluent builder for {@see AnalysisPipeline} instances in tests.
 *
 * Production wiring (RuntimeConfigurator) configures an
 * {@see ArchitectureConfiguration} on the policy before
 * {@code AnalysisPipeline::analyze()} runs, satisfying the ADR 0008 §3
 * fail-fast invariant (bind → prepare).
 *
 * Tests that construct an {@code AnalysisPipeline} directly bypass that
 * wiring, so they must hand the pipeline an already-configured policy. This
 * builder centralises that concern: by default it constructs a real
 * {@see ArchitecturePolicy} and binds {@see ArchitectureConfiguration::empty()},
 * which is sufficient for every test that does not exercise architecture
 * rules. Tests that need a non-empty configuration can supply one through
 * {@see self::withArchitectureConfiguration()} or inject a custom preparation
 * contract through {@see self::withLayerPolicyPreparation()}.
 *
 * The other constructor arguments mirror {@see AnalysisPipeline}'s ctor.
 * Every collaborator is required from the caller's perspective — there are
 * no implicit mocks — to keep test setup explicit. The single deliberate
 * convenience is the policy default.
 */
final class TestPipelineBuilder
{
    private ?FileDiscoveryInterface $defaultDiscovery = null;

    private ?CollectionOrchestratorInterface $collectionOrchestrator = null;

    private ?RuleExecutionInterface $ruleExecutor = null;

    private ?RuleConfigurationInterface $ruleConfiguration = null;

    private ?MeasurementAggregationInterface $measurementAggregation = null;

    private ?ComputedMetricEvaluator $computedMetricEvaluation = null;

    private ?CircularDependencyPreparationInterface $circularDependencyPreparation = null;

    private ?FileSetInspectionComposite $fileSetInspection = null;

    private ?LayerPolicyPreparationInterface $layerPolicyPreparation = null;

    private ?ArchitectureConfiguration $architectureConfiguration = null;

    private ?MetricRepositoryFactoryInterface $repositoryFactory = null;

    private ?DependencyGraphBuilderInterface $graphBuilder = null;

    private ?InlineDirectivePolicyInterface $inlineDirectivePolicy = null;

    private ?ThresholdDirectiveAuditInterface $thresholdDirectiveAudit = null;

    private ?LoggerInterface $logger = null;

    private ?ProfilerInterface $profiler = null;

    private ?RuleSelector $ruleSelector = null;

    private function __construct() {}

    public static function create(): self
    {
        return new self();
    }

    public function withDefaultDiscovery(FileDiscoveryInterface $discovery): self
    {
        $this->defaultDiscovery = $discovery;

        return $this;
    }

    public function withCollectionOrchestrator(CollectionOrchestratorInterface $orchestrator): self
    {
        $this->collectionOrchestrator = $orchestrator;

        return $this;
    }

    public function withThresholdDirectiveAudit(ThresholdDirectiveAuditInterface $audit): self
    {
        $this->thresholdDirectiveAudit = $audit;

        return $this;
    }

    public function withRuleExecution(RuleExecutionInterface $ruleExecutor): self
    {
        $this->ruleExecutor = $ruleExecutor;

        return $this;
    }

    public function withRuleConfiguration(RuleConfigurationInterface $ruleConfiguration): self
    {
        $this->ruleConfiguration = $ruleConfiguration;

        return $this;
    }

    public function withMeasurementAggregation(MeasurementAggregationInterface $aggregation): self
    {
        $this->measurementAggregation = $aggregation;

        return $this;
    }

    public function withComputedMetricEvaluation(ComputedMetricEvaluator $evaluation): self
    {
        $this->computedMetricEvaluation = $evaluation;

        return $this;
    }

    public function withCircularDependencyPreparation(CircularDependencyPreparationInterface $preparation): self
    {
        $this->circularDependencyPreparation = $preparation;

        return $this;
    }

    public function withFileSetInspection(FileSetInspectionComposite $inspection): self
    {
        $this->fileSetInspection = $inspection;

        return $this;
    }

    /**
     * Inject a policy preparation contract. Use this for tests that need to
     * verify the Run-to-Architecture lifecycle interaction.
     */
    public function withLayerPolicyPreparation(LayerPolicyPreparationInterface $preparation): self
    {
        $this->layerPolicyPreparation = $preparation;

        return $this;
    }

    /**
     * Use the default {@see ArchitecturePolicy}, but bind it to a specific
     * configuration instead of {@see ArchitectureConfiguration::empty()}.
     * Useful for tests that exercise architecture rules with real layer
     * definitions.
     */
    public function withArchitectureConfiguration(ArchitectureConfiguration $configuration): self
    {
        $this->architectureConfiguration = $configuration;

        return $this;
    }

    public function withRepositoryFactory(MetricRepositoryFactoryInterface $factory): self
    {
        $this->repositoryFactory = $factory;

        return $this;
    }

    public function withGraphBuilder(DependencyGraphBuilderInterface $builder): self
    {
        $this->graphBuilder = $builder;

        return $this;
    }

    public function withLogger(LoggerInterface $logger): self
    {
        $this->logger = $logger;

        return $this;
    }

    public function withProfiler(ProfilerInterface $profiler): self
    {
        $this->profiler = $profiler;

        return $this;
    }

    public function withRuleSelector(RuleSelector $ruleSelector): self
    {
        $this->ruleSelector = $ruleSelector;

        return $this;
    }

    public function withInlineDirectivePolicy(InlineDirectivePolicyInterface $policy): self
    {
        $this->inlineDirectivePolicy = $policy;

        return $this;
    }

    /**
     * The directive policy is the one collaborator with a safe default: a
     * pipeline that is not exercising inline directives still has to hand
     * them somewhere, and a real policy over an empty channel universe
     * reports nothing rather than pretending.
     */
    private function resolveInlineDirectivePolicy(): InlineDirectivePolicyInterface
    {
        $universe = new ChannelUniverse([], [], [], new ResolvedComputedMetricDefinitions([]));

        return $this->inlineDirectivePolicy ?? new InlineDirectivePolicy(new DirectiveUsage(
            $universe,
            $this->ruleSelector ?? new RuleSelector(new InMemoryRuleChannelRegistry()),
            $this->ruleConfiguration ?? new RuleOptionsRegistry(),
            $universe,
        ));
    }

    public function build(): AnalysisPipeline
    {
        return new AnalysisPipeline(
            analysisFileDiscovery: new AnalysisFileDiscovery(
                $this->defaultDiscovery ?? throw new LogicException(
                    'TestPipelineBuilder: defaultDiscovery is required (call withDefaultDiscovery())',
                ),
                new GeneratedFileFilter(),
            ),
            collectionOrchestrator: $this->collectionOrchestrator ?? throw new LogicException(
                'TestPipelineBuilder: collectionOrchestrator is required (call withCollectionOrchestrator())',
            ),
            ruleExecutor: $this->ruleExecutor ?? throw new LogicException(
                'TestPipelineBuilder: ruleExecutor is required (call withRuleExecution())',
            ),
            ruleProducerPreparation: new RuleProducerPreparation(
                $this->resolveLayerPolicyPreparation(),
                $this->circularDependencyPreparation ?? throw new LogicException(
                    'TestPipelineBuilder: circularDependencyPreparation is required '
                    . '(call withCircularDependencyPreparation())',
                ),
                $this->resolveInlineDirectivePolicy(),
                $this->thresholdDirectiveAudit ?? self::inertThresholdAudit(),
                $this->fileSetInspection ?? throw new LogicException(
                    'TestPipelineBuilder: fileSetInspection is required (call withFileSetInspection())',
                ),
                $this->ruleSelector ?? new RuleSelector(new InMemoryRuleChannelRegistry()),
                $this->ruleConfiguration ?? new RuleOptionsRegistry(),
            ),
            measurementAggregation: $this->measurementAggregation ?? throw new LogicException(
                'TestPipelineBuilder: measurementAggregation is required (call withMeasurementAggregation())',
            ),
            computedMetricEvaluation: $this->computedMetricEvaluation ?? throw new LogicException(
                'TestPipelineBuilder: computedMetricEvaluation is required (call withComputedMetricEvaluation())',
            ),
            repositoryFactory: $this->repositoryFactory ?? new DefaultMetricRepositoryFactory(),
            graphBuilder: $this->graphBuilder ?? AdjacencyGraphBuilder::builder(),
            logger: $this->logger ?? new NullLogger(),
            profiler: $this->profiler ?? throw new LogicException(
                'TestPipelineBuilder: profiler is required (call withProfiler())',
            ),
        );
    }

    /**
     * The threshold audit a pipeline test does not exercise.
     *
     * An anonymous implementation rather than a stub: the builder is not a
     * test case, and a sweep that answers "no directives" is what a run
     * without threshold annotations produces anyway.
     */
    private static function inertThresholdAudit(): ThresholdDirectiveAuditInterface
    {
        return new class implements ThresholdDirectiveAuditInterface {
            public function verdicts(ThresholdDirectiveAuditInput $input): array
            {
                return [];
            }
        };
    }

    private function resolveLayerPolicyPreparation(): LayerPolicyPreparationInterface
    {
        if ($this->layerPolicyPreparation !== null) {
            if ($this->architectureConfiguration !== null) {
                throw new LogicException(
                    'TestPipelineBuilder: cannot combine withLayerPolicyPreparation() and '
                    . 'withArchitectureConfiguration() — the explicit preparation is responsible '
                    . 'for its own state',
                );
            }

            return $this->layerPolicyPreparation;
        }

        $processor = new ArchitecturePolicy();
        $processor->bind($this->architectureConfiguration ?? ArchitectureConfiguration::empty());

        return $processor;
    }
}
