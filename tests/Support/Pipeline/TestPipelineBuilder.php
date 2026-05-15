<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Support\Pipeline;

use LogicException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Qualimetrix\Analysis\Collection\CollectionOrchestratorInterface;
use Qualimetrix\Analysis\Collection\Dependency\DependencyGraphBuilder;
use Qualimetrix\Analysis\Discovery\FileDiscoveryInterface;
use Qualimetrix\Analysis\Pipeline\AnalysisPipeline;
use Qualimetrix\Analysis\Pipeline\MetricEnricher;
use Qualimetrix\Analysis\Repository\DefaultMetricRepositoryFactory;
use Qualimetrix\Analysis\Repository\MetricRepositoryFactoryInterface;
use Qualimetrix\Analysis\RuleExecution\RuleExecutorInterface;
use Qualimetrix\Architecture\Domain\ArchitectureConfiguration;
use Qualimetrix\Architecture\Processing\ArchitectureProcessor;
use Qualimetrix\Architecture\Processing\ArchitectureProcessorInterface;
use Qualimetrix\Configuration\ConfigurationProviderInterface;
use Qualimetrix\Core\Profiler\ProfilerHolder;

/**
 * Fluent builder for {@see AnalysisPipeline} instances in tests.
 *
 * Production wiring (RuntimeConfigurator) binds an
 * {@see ArchitectureConfiguration} on the processor before
 * {@code AnalysisPipeline::analyze()} runs, satisfying the ADR 0008 §3
 * fail-fast invariant (bind → prepare).
 *
 * Tests that construct an {@code AnalysisPipeline} directly bypass that
 * wiring, so they must hand the pipeline an already-bound processor. This
 * builder centralises that concern: by default it constructs a real
 * {@see ArchitectureProcessor} and binds {@see ArchitectureConfiguration::empty()},
 * which is sufficient for every test that does not exercise architecture
 * rules. Tests that need a non-empty configuration can supply one through
 * {@see self::withArchitectureConfiguration()} or inject a custom processor
 * through {@see self::withArchitectureProcessor()}.
 *
 * The other constructor arguments mirror {@see AnalysisPipeline}'s ctor.
 * Every collaborator is required from the caller's perspective — there are
 * no implicit mocks — to keep test setup explicit. The single deliberate
 * convenience is the processor default.
 */
final class TestPipelineBuilder
{
    private ?FileDiscoveryInterface $defaultDiscovery = null;

    private ?CollectionOrchestratorInterface $collectionOrchestrator = null;

    private ?RuleExecutorInterface $ruleExecutor = null;

    private ?ConfigurationProviderInterface $configurationProvider = null;

    private ?MetricEnricher $metricEnricher = null;

    private ?ArchitectureProcessorInterface $architectureProcessor = null;

    private ?ArchitectureConfiguration $architectureConfiguration = null;

    private ?MetricRepositoryFactoryInterface $repositoryFactory = null;

    private ?DependencyGraphBuilder $graphBuilder = null;

    private ?LoggerInterface $logger = null;

    private ?ProfilerHolder $profilerHolder = null;

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

    public function withRuleExecutor(RuleExecutorInterface $ruleExecutor): self
    {
        $this->ruleExecutor = $ruleExecutor;

        return $this;
    }

    public function withConfigurationProvider(ConfigurationProviderInterface $provider): self
    {
        $this->configurationProvider = $provider;

        return $this;
    }

    public function withMetricEnricher(MetricEnricher $enricher): self
    {
        $this->metricEnricher = $enricher;

        return $this;
    }

    /**
     * Inject a fully prepared processor — caller is responsible for calling
     * {@code bind()} before handing it to the builder. Use this for tests
     * that need to verify processor lifecycle interactions.
     */
    public function withArchitectureProcessor(ArchitectureProcessorInterface $processor): self
    {
        $this->architectureProcessor = $processor;

        return $this;
    }

    /**
     * Use the default {@see ArchitectureProcessor}, but bind it to a specific
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

    public function withGraphBuilder(DependencyGraphBuilder $builder): self
    {
        $this->graphBuilder = $builder;

        return $this;
    }

    public function withLogger(LoggerInterface $logger): self
    {
        $this->logger = $logger;

        return $this;
    }

    public function withProfilerHolder(?ProfilerHolder $holder): self
    {
        $this->profilerHolder = $holder;

        return $this;
    }

    public function build(): AnalysisPipeline
    {
        return new AnalysisPipeline(
            defaultDiscovery: $this->defaultDiscovery ?? throw new LogicException(
                'TestPipelineBuilder: defaultDiscovery is required (call withDefaultDiscovery())',
            ),
            collectionOrchestrator: $this->collectionOrchestrator ?? throw new LogicException(
                'TestPipelineBuilder: collectionOrchestrator is required (call withCollectionOrchestrator())',
            ),
            ruleExecutor: $this->ruleExecutor ?? throw new LogicException(
                'TestPipelineBuilder: ruleExecutor is required (call withRuleExecutor())',
            ),
            configurationProvider: $this->configurationProvider ?? throw new LogicException(
                'TestPipelineBuilder: configurationProvider is required (call withConfigurationProvider())',
            ),
            metricEnricher: $this->metricEnricher ?? throw new LogicException(
                'TestPipelineBuilder: metricEnricher is required (call withMetricEnricher())',
            ),
            architectureProcessor: $this->resolveArchitectureProcessor(),
            repositoryFactory: $this->repositoryFactory ?? new DefaultMetricRepositoryFactory(),
            graphBuilder: $this->graphBuilder,
            logger: $this->logger ?? new NullLogger(),
            profilerHolder: $this->profilerHolder,
        );
    }

    private function resolveArchitectureProcessor(): ArchitectureProcessorInterface
    {
        if ($this->architectureProcessor !== null) {
            if ($this->architectureConfiguration !== null) {
                throw new LogicException(
                    'TestPipelineBuilder: cannot combine withArchitectureProcessor() and '
                    . 'withArchitectureConfiguration() — the explicit processor is responsible '
                    . 'for its own bind() state',
                );
            }

            return $this->architectureProcessor;
        }

        $processor = new ArchitectureProcessor();
        $processor->bind($this->architectureConfiguration ?? ArchitectureConfiguration::empty());

        return $processor;
    }
}
