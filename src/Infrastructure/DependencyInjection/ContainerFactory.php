<?php

declare(strict_types=1);

namespace AiMessDetector\Infrastructure\DependencyInjection;

use AiMessDetector\Analysis\Aggregation\GlobalCollectorRunner;
use AiMessDetector\Analysis\Collection\CollectionOrchestrator;
use AiMessDetector\Analysis\Collection\CollectionOrchestratorInterface;
use AiMessDetector\Analysis\Collection\Dependency\DependencyGraphBuilder;
use AiMessDetector\Analysis\Collection\Dependency\DependencyResolver;
use AiMessDetector\Analysis\Collection\Dependency\DependencyVisitor;
use AiMessDetector\Analysis\Collection\FileProcessor;
use AiMessDetector\Analysis\Collection\FileProcessorInterface;
use AiMessDetector\Analysis\Collection\Metric\CompositeCollector;
use AiMessDetector\Analysis\Collection\Strategy\StrategySelectorInterface;
use AiMessDetector\Analysis\Discovery\FileDiscoveryInterface;
use AiMessDetector\Analysis\Discovery\FinderFileDiscovery;
use AiMessDetector\Analysis\Namespace_\ChainNamespaceDetector;
use AiMessDetector\Analysis\Namespace_\Psr4NamespaceDetector;
use AiMessDetector\Analysis\Namespace_\TokenizerNamespaceDetector;
use AiMessDetector\Analysis\Pipeline\AnalysisPipeline;
use AiMessDetector\Analysis\Pipeline\AnalysisPipelineInterface;
use AiMessDetector\Analysis\Repository\InMemoryMetricRepository;
use AiMessDetector\Analysis\RuleExecution\RuleExecutor;
use AiMessDetector\Analysis\RuleExecution\RuleExecutorInterface;
use AiMessDetector\Baseline\BaselineGenerator;
use AiMessDetector\Baseline\BaselineLoader;
use AiMessDetector\Baseline\BaselineWriter;
use AiMessDetector\Baseline\Suppression\SuppressionFilter;
use AiMessDetector\Baseline\ViolationHasher;
use AiMessDetector\Configuration\AnalysisConfiguration;
use AiMessDetector\Configuration\ConfigurationHolder;
use AiMessDetector\Configuration\ConfigurationProviderInterface;
use AiMessDetector\Configuration\Loader\ConfigLoaderInterface;
use AiMessDetector\Configuration\Loader\YamlConfigLoader;
use AiMessDetector\Configuration\Pipeline\ConfigurationPipeline;
use AiMessDetector\Configuration\Pipeline\Stage\ConfigurationStageInterface;
use AiMessDetector\Configuration\RuleOptionsFactory;
use AiMessDetector\Core\Ast\FileParserInterface;
use AiMessDetector\Core\Metric\DerivedCollectorInterface;
use AiMessDetector\Core\Metric\GlobalContextCollectorInterface;
use AiMessDetector\Core\Metric\MetricCollectorInterface;
use AiMessDetector\Core\Metric\MetricRepositoryInterface;
use AiMessDetector\Core\Namespace_\NamespaceDetectorInterface;
use AiMessDetector\Core\Profiler\ProfilerHolder;
use AiMessDetector\Core\Progress\ProgressReporter;
use AiMessDetector\Core\Rule\RuleInterface;
use AiMessDetector\Infrastructure\Ast\CachedFileParser;
use AiMessDetector\Infrastructure\Ast\FileParserFactory;
use AiMessDetector\Infrastructure\Ast\PhpFileParser;
use AiMessDetector\Infrastructure\Cache\CacheFactory;
use AiMessDetector\Infrastructure\Cache\CacheInterface;
use AiMessDetector\Infrastructure\Cache\CacheKeyGenerator;
use AiMessDetector\Infrastructure\Console\Command\AnalyzeCommand;
use AiMessDetector\Infrastructure\Console\Command\BaselineCleanupCommand;
use AiMessDetector\Infrastructure\Console\Command\GraphExportCommand;
use AiMessDetector\Infrastructure\Console\Command\HookInstallCommand;
use AiMessDetector\Infrastructure\Console\Command\HookStatusCommand;
use AiMessDetector\Infrastructure\Console\Command\HookUninstallCommand;
use AiMessDetector\Infrastructure\Console\Progress\DelegatingProgressReporter;
use AiMessDetector\Infrastructure\Console\Progress\ProgressReporterHolder;
use AiMessDetector\Infrastructure\DependencyInjection\CompilerPass\CollectorCompilerPass;
use AiMessDetector\Infrastructure\DependencyInjection\CompilerPass\ConfigurationStageCompilerPass;
use AiMessDetector\Infrastructure\DependencyInjection\CompilerPass\FormatterCompilerPass;
use AiMessDetector\Infrastructure\DependencyInjection\CompilerPass\GlobalCollectorCompilerPass;
use AiMessDetector\Infrastructure\DependencyInjection\CompilerPass\ParallelCollectorClassesCompilerPass;
use AiMessDetector\Infrastructure\DependencyInjection\CompilerPass\RuleCompilerPass;
use AiMessDetector\Infrastructure\DependencyInjection\CompilerPass\RuleOptionsCompilerPass;
use AiMessDetector\Infrastructure\DependencyInjection\CompilerPass\RuleRegistryCompilerPass;
use AiMessDetector\Infrastructure\Logging\DelegatingLogger;
use AiMessDetector\Infrastructure\Logging\LoggerFactory;
use AiMessDetector\Infrastructure\Logging\LoggerHolder;
use AiMessDetector\Infrastructure\Parallel\Strategy\AmphpParallelStrategy;
use AiMessDetector\Infrastructure\Parallel\Strategy\SequentialStrategy;
use AiMessDetector\Infrastructure\Parallel\Strategy\StrategySelector;
use AiMessDetector\Infrastructure\Parallel\Strategy\WorkerCountDetector;
use AiMessDetector\Infrastructure\Rule\RuleRegistry;
use AiMessDetector\Infrastructure\Rule\RuleRegistryInterface;
use AiMessDetector\Reporting\Formatter\FormatterInterface;
use AiMessDetector\Reporting\Formatter\FormatterRegistry;
use AiMessDetector\Reporting\Formatter\FormatterRegistryInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Unified factory for creating the DI container.
 *
 * This single container provides all services needed for both CLI and analysis:
 * - RuleRegistry with rule classes (for CLI option discovery)
 * - ConfigLoader for reading configuration files
 * - AnalyzeCommand with injected dependencies
 * - All analysis services (Analyzer, Collectors, Rules, etc.)
 *
 * Runtime configuration is handled through ConfigurationProviderInterface and
 * RuleOptionsFactory, which can be configured after container creation but
 * before rules are instantiated (rules are lazy-loaded).
 */
final class ContainerFactory
{
    /**
     * Create a fully configured container.
     *
     * The container is created with default configuration. Runtime configuration
     * (from CLI or config file) should be set through:
     * - ConfigurationProviderInterface::setConfiguration()
     * - RuleOptionsFactory::setCliOptions()
     *
     * These must be called BEFORE rules are used (e.g., before Analyzer::analyze()).
     */
    public function create(): ContainerBuilder
    {
        $container = new ContainerBuilder();

        // Configure global aliases for autowiring
        $this->configureDefaults($container);

        // Autoconfigure: all RuleInterface implementations get tagged and made lazy
        $container->registerForAutoconfiguration(RuleInterface::class)
            ->addTag(RuleCompilerPass::TAG)
            ->setLazy(true);

        // Autoconfigure: all collector interfaces get auto-tagged
        $container->registerForAutoconfiguration(MetricCollectorInterface::class)
            ->addTag(CollectorCompilerPass::TAG);

        $container->registerForAutoconfiguration(DerivedCollectorInterface::class)
            ->addTag(CollectorCompilerPass::TAG_DERIVED);

        $container->registerForAutoconfiguration(GlobalContextCollectorInterface::class)
            ->addTag(GlobalCollectorCompilerPass::TAG);

        // Autoconfigure: all formatters get auto-tagged
        $container->registerForAutoconfiguration(FormatterInterface::class)
            ->addTag(FormatterCompilerPass::TAG);

        // Configuration stages autoconfiguration
        $container->registerForAutoconfiguration(ConfigurationStageInterface::class)
            ->addTag(ConfigurationStageCompilerPass::TAG);

        // Register configuration provider (runtime-configurable)
        $this->registerConfigurationHolder($container);

        // Register configuration pipeline
        $this->registerConfigurationPipeline($container);

        // Register logging infrastructure (runtime-configurable)
        $this->registerLogging($container);

        // Register progress reporting (runtime-configurable)
        $this->registerProgress($container);

        // Register profiler (runtime-configurable)
        $this->registerProfiler($container);

        // Register cache infrastructure
        $this->registerCache($container);

        // Register parser infrastructure
        $this->registerParsers($container);

        // Register namespace detection
        $this->registerNamespaceDetection($container);

        // Register collectors
        $this->registerCollectors($container);

        // Register parallel collection infrastructure
        $this->registerParallel($container);

        // Register rules statically
        $this->registerRules($container);

        // Register rule registry (collects rule classes via compiler pass)
        $this->registerRuleRegistry($container);

        // Register analysis components
        $this->registerAnalysis($container);

        // Register formatters
        $this->registerFormatters($container);

        // Register baseline components
        $this->registerBaseline($container);

        // Register CLI infrastructure
        $this->registerCli($container);

        // Add compiler passes
        $container->addCompilerPass(new CollectorCompilerPass());
        $container->addCompilerPass(new GlobalCollectorCompilerPass());
        $container->addCompilerPass(new ParallelCollectorClassesCompilerPass());
        $container->addCompilerPass(new RuleRegistryCompilerPass());
        // RuleOptionsCompilerPass MUST run AFTER autoconfiguration (TYPE_OPTIMIZE)
        // but BEFORE RuleCompilerPass. Using TYPE_BEFORE_REMOVING with high priority.
        $container->addCompilerPass(
            new RuleOptionsCompilerPass(),
            PassConfig::TYPE_BEFORE_REMOVING,
            100, // High priority to run before RuleCompilerPass
        );
        $container->addCompilerPass(
            new RuleCompilerPass(),
            PassConfig::TYPE_BEFORE_REMOVING,
            50, // Lower priority, runs after RuleOptionsCompilerPass
        );
        $container->addCompilerPass(new FormatterCompilerPass());
        $container->addCompilerPass(new ConfigurationStageCompilerPass());

        // Compile container
        $container->compile();

        return $container;
    }

    /**
     * Configures global aliases for autowiring.
     *
     * These aliases allow services to depend on LoggerInterface/ProgressReporter
     * without knowing the concrete implementation (DelegatingLogger/DelegatingProgressReporter).
     */
    private function configureDefaults(ContainerBuilder $container): void
    {
        // After DelegatingLogger is registered, alias LoggerInterface to it
        // This allows autowiring of LoggerInterface to resolve to DelegatingLogger
        $container->registerAliasForArgument(DelegatingLogger::class, LoggerInterface::class);

        // After DelegatingProgressReporter is registered, alias ProgressReporter to it
        $container->registerAliasForArgument(DelegatingProgressReporter::class, ProgressReporter::class);
    }

    /**
     * Registers configuration providers as mutable singletons.
     *
     * These are initialized with defaults and can be reconfigured at runtime
     * through setConfiguration()/setCliOptions() before rules are instantiated.
     */
    private function registerConfigurationHolder(ContainerBuilder $container): void
    {
        // RuleOptionsFactory - mutable, can be configured with CLI options at runtime
        $container->register(RuleOptionsFactory::class)
            ->setSynthetic(true)
            ->setPublic(true);
        $container->set(RuleOptionsFactory::class, new RuleOptionsFactory());

        // ConfigurationHolder - mutable, configured at runtime with merged config
        $configProvider = new ConfigurationHolder();
        $configProvider->setConfiguration(new AnalysisConfiguration());

        $container->register(ConfigurationProviderInterface::class)
            ->setSynthetic(true)
            ->setPublic(true);
        $container->set(ConfigurationProviderInterface::class, $configProvider);
    }

    /**
     * Registers configuration pipeline with stages.
     *
     * Stages are auto-registered from src/Configuration/Pipeline/Stage/*
     * and automatically tagged via autoconfiguration.
     */
    private function registerConfigurationPipeline(ContainerBuilder $container): void
    {
        $srcDir = \dirname(__DIR__, 2); // src/
        $loader = new PhpFileLoader($container, new FileLocator($srcDir));

        // Register ComposerReader (required by ComposerDiscoveryStage)
        $container->register(\AiMessDetector\Configuration\Discovery\ComposerReader::class)
            ->setAutowired(true);

        // Auto-register all configuration stages from src/Configuration/Pipeline/Stage/*
        // Classes implementing ConfigurationStageInterface will be auto-tagged via registerForAutoconfiguration
        $prototype = (new Definition())
            ->setAutoconfigured(true)
            ->setAutowired(true);
        $loader->registerClasses(
            $prototype,
            'AiMessDetector\\Configuration\\Pipeline\\Stage\\',
            $srcDir . '/Configuration/Pipeline/Stage/*',
            $srcDir . '/Configuration/Pipeline/Stage/*Interface.php',
        );

        // ConfigurationPipeline will be populated by ConfigurationStageCompilerPass
        $container->register(ConfigurationPipeline::class)
            ->setPublic(true);
    }

    /**
     * Registers logging infrastructure.
     *
     * LoggerHolder is a mutable singleton that holds the current logger.
     * It's initialized with NullLogger and can be reconfigured at runtime
     * in AnalyzeCommand based on CLI options (-v, --log-file, etc.).
     *
     * DelegatingLogger proxies all log calls to LoggerHolder::getLogger(),
     * allowing runtime logger configuration while services are created at compile time.
     */
    private function registerLogging(ContainerBuilder $container): void
    {
        // LoggerFactory for creating loggers
        $container->register(LoggerFactory::class)
            ->setPublic(true);

        // LoggerHolder - mutable, holds current logger
        $container->register(LoggerHolder::class)
            ->setSynthetic(true)
            ->setPublic(true);
        $container->set(LoggerHolder::class, new LoggerHolder());

        // DelegatingLogger - proxies to LoggerHolder
        // Note: LoggerHolder is synthetic, so we can't use autowiring here
        $container->register(DelegatingLogger::class)
            ->setArguments([new Reference(LoggerHolder::class)]);
    }

    /**
     * Registers progress reporting infrastructure.
     *
     * ProgressReporterHolder is a mutable singleton that holds the current progress reporter.
     * It's initialized with NullProgressReporter and can be reconfigured at runtime
     * in AnalyzeCommand based on CLI options (--no-progress, -q, TTY detection).
     *
     * DelegatingProgressReporter proxies all progress calls to ProgressReporterHolder::getReporter(),
     * allowing runtime progress reporter configuration while services are created at compile time.
     */
    private function registerProgress(ContainerBuilder $container): void
    {
        // ProgressReporterHolder - mutable, holds current progress reporter
        $container->register(ProgressReporterHolder::class)
            ->setSynthetic(true)
            ->setPublic(true);
        $container->set(ProgressReporterHolder::class, new ProgressReporterHolder());

        // DelegatingProgressReporter - proxies to ProgressReporterHolder
        // Note: ProgressReporterHolder is synthetic, so we can't use autowiring here
        $container->register(DelegatingProgressReporter::class)
            ->setArguments([new Reference(ProgressReporterHolder::class)]);
    }

    /**
     * Registers profiler infrastructure.
     *
     * ProfilerHolder is a mutable singleton that holds the current profiler.
     * It's initialized with NullProfiler (no-op) and can be reconfigured at runtime
     * in AnalyzeCommand based on CLI options (--profile).
     */
    private function registerProfiler(ContainerBuilder $container): void
    {
        // ProfilerHolder - mutable, holds current profiler
        $container->register(ProfilerHolder::class)
            ->setSynthetic(true)
            ->setPublic(true);
        $container->set(ProfilerHolder::class, new ProfilerHolder());
    }

    private function registerCache(ContainerBuilder $container): void
    {
        $container->register(CacheKeyGenerator::class);

        // CacheFactory creates FileCache lazily based on runtime configuration
        // Note: ConfigurationProviderInterface is synthetic, so we can't use autowiring here
        $container->register(CacheFactory::class)
            ->setArguments([new Reference(ConfigurationProviderInterface::class)])
            ->setPublic(true);

        // CacheInterface is created through factory
        $container->register(CacheInterface::class)
            ->setFactory([new Reference(CacheFactory::class), 'create'])
            ->setPublic(true);
    }

    private function registerParsers(ContainerBuilder $container): void
    {
        $container->register(PhpFileParser::class)
            ->setArguments([
                '$parser' => null,
                '$logger' => new Reference(DelegatingLogger::class),
            ]);

        $container->register(CachedFileParser::class)
            ->setArguments([
                new Reference(PhpFileParser::class),
                new Reference(CacheInterface::class),
                new Reference(CacheKeyGenerator::class),
            ]);

        $container->register(FileParserFactory::class)
            ->setArguments([
                new Reference(PhpFileParser::class),
                new Reference(CacheInterface::class),
                new Reference(CacheKeyGenerator::class),
                new Reference(ConfigurationProviderInterface::class),
            ]);

        // Register FileParserInterface using factory
        $container->register(FileParserInterface::class)
            ->setFactory([new Reference(FileParserFactory::class), 'create']);
    }

    private function registerNamespaceDetection(ContainerBuilder $container): void
    {
        $container->register(TokenizerNamespaceDetector::class);

        // Use default composer.json path; runtime config can override PSR-4 mappings
        $container->register(Psr4NamespaceDetector::class)
            ->setArguments(['composer.json']);

        // Chain detector with PSR-4 first, then tokenizer as fallback
        $container->register(ChainNamespaceDetector::class)
            ->setArguments([[
                new Reference(Psr4NamespaceDetector::class),
                new Reference(TokenizerNamespaceDetector::class),
            ]]);

        $container->setAlias(NamespaceDetectorInterface::class, ChainNamespaceDetector::class);
    }

    private function registerCollectors(ContainerBuilder $container): void
    {
        $srcDir = \dirname(__DIR__, 2); // src/
        $loader = new PhpFileLoader($container, new FileLocator($srcDir));

        // Auto-register all metric collectors from src/Metrics/*
        // Classes implementing MetricCollectorInterface, DerivedCollectorInterface,
        // or GlobalContextCollectorInterface will be auto-tagged via registerForAutoconfiguration
        $prototype = (new Definition())->setAutoconfigured(true)->setAutowired(true);
        $loader->registerClasses(
            $prototype,
            'AiMessDetector\\Metrics\\',
            $srcDir . '/Metrics/*',
            $srcDir . '/Metrics/{Abstract*.php,*Interface.php,*Visitor.php,*ClassData.php,*Metrics.php,*Calculator.php}',
        );

        // Auto-register global context collectors from src/Metrics/Coupling/*
        // (These implement GlobalContextCollectorInterface and are auto-tagged)

        // DependencyResolver for resolving class names to FQN
        $container->register(DependencyResolver::class);

        // DependencyVisitor for collecting dependencies during AST traversal
        $container->register(DependencyVisitor::class)
            ->setArguments([
                new Reference(DependencyResolver::class),
            ]);

        // CompositeCollector will be populated by compiler pass
        // Also receives DependencyVisitor for unified AST traversal (metrics + dependencies)
        $container->register(CompositeCollector::class)
            ->setArguments([[], []])
            ->addMethodCall('setDependencyVisitor', [new Reference(DependencyVisitor::class)])
            ->setPublic(true);
    }

    private function registerParallel(ContainerBuilder $container): void
    {
        // WorkerCountDetector for auto-detecting CPU cores
        $container->register(WorkerCountDetector::class);

        // AmphpParallelStrategy for parallel processing via amphp/parallel
        $container->register(AmphpParallelStrategy::class);

        // SequentialStrategy as fallback
        $container->register(SequentialStrategy::class);

        // StrategySelector chooses and configures best available strategy
        $container->register(StrategySelector::class)
            ->setArguments([
                new Reference(AmphpParallelStrategy::class),
                new Reference(SequentialStrategy::class),
                new Reference(ConfigurationProviderInterface::class),
                new Reference(WorkerCountDetector::class),
                new Reference(DelegatingLogger::class),
            ]);
    }

    /**
     * Registers all rules automatically via registerClasses().
     *
     * Rules are discovered from src/Rules/**\/*Rule.php and auto-tagged via
     * registerForAutoconfiguration(RuleInterface::class). Their Options
     * are registered by RuleOptionsCompilerPass using Rule::getOptionsClass().
     *
     * This approach eliminates manual registration when adding new rules:
     * just create the Rule class and Options class, and they're automatically
     * registered without touching ContainerFactory.
     *
     * NOTE: Autowiring is DISABLED for rules because their constructor takes
     * RuleOptionsInterface which requires CompilerPass to resolve correctly.
     * RuleOptionsCompilerPass injects the correct Options reference.
     */
    private function registerRules(ContainerBuilder $container): void
    {
        $srcDir = \dirname(__DIR__, 2); // src/
        $loader = new PhpFileLoader($container, new FileLocator($srcDir));

        // Auto-register all *Rule.php from src/Rules/**
        // Classes implementing RuleInterface will be auto-tagged and made lazy
        // via registerForAutoconfiguration() in create()
        // Autowiring is DISABLED - RuleOptionsCompilerPass handles argument injection
        $prototype = (new Definition())
            ->setAutoconfigured(true)
            ->setAutowired(false)
            ->setLazy(true);

        $loader->registerClasses(
            $prototype,
            'AiMessDetector\\Rules\\',
            $srcDir . '/Rules/**/*Rule.php',
            $srcDir . '/Rules/AbstractRule.php',
        );
    }

    private function registerRuleRegistry(ContainerBuilder $container): void
    {
        // RuleRegistry will have rule classes injected by RuleRegistryCompilerPass
        $container->register(RuleRegistry::class)
            ->setArguments(['$ruleClasses' => []])
            ->setPublic(true);

        $container->setAlias(RuleRegistryInterface::class, RuleRegistry::class)
            ->setPublic(true);
    }

    private function registerAnalysis(ContainerBuilder $container): void
    {
        $container->register(FinderFileDiscovery::class);
        $container->setAlias(FileDiscoveryInterface::class, FinderFileDiscovery::class);

        $container->register(InMemoryMetricRepository::class);
        $container->setAlias(MetricRepositoryInterface::class, InMemoryMetricRepository::class);

        // FileProcessor - processes single files
        $container->register(FileProcessor::class)
            ->setArguments([
                new Reference(FileParserInterface::class),
                new Reference(CompositeCollector::class),
            ]);
        $container->setAlias(FileProcessorInterface::class, FileProcessor::class);

        // StrategySelectorInterface - for lazy strategy selection
        $container->setAlias(StrategySelectorInterface::class, StrategySelector::class);

        // CollectionOrchestrator - coordinates collection phase
        // Uses StrategySelectorInterface for lazy strategy selection (configuration may not be available at DI time)
        $container->register(CollectionOrchestrator::class)
            ->setArguments([
                new Reference(FileProcessorInterface::class),
                new Reference(StrategySelectorInterface::class),
                new Reference(CompositeCollector::class),
                new Reference(DelegatingProgressReporter::class),
                new Reference(DelegatingLogger::class),
            ]);
        $container->setAlias(CollectionOrchestratorInterface::class, CollectionOrchestrator::class);

        // GlobalCollectorRunner - runs global collectors
        // Global collectors will be injected by GlobalCollectorCompilerPass
        $container->register(GlobalCollectorRunner::class)
            ->setArguments([
                '$collectors' => [], // Will be set by GlobalCollectorCompilerPass
            ]);

        // RuleExecutor will have rules injected by compiler pass
        $container->register(RuleExecutor::class)
            ->setArguments([
                '$rules' => [], // Will be set by RuleCompilerPass
                '$configurationProvider' => new Reference(ConfigurationProviderInterface::class),
            ]);
        $container->setAlias(RuleExecutorInterface::class, RuleExecutor::class);

        // DependencyGraphBuilder for dependency analysis
        $container->register(DependencyGraphBuilder::class);

        // AnalysisPipeline - main orchestrator
        $container->register(AnalysisPipeline::class)
            ->setArguments([
                new Reference(FileDiscoveryInterface::class),
                new Reference(CollectionOrchestratorInterface::class),
                new Reference(CompositeCollector::class),
                new Reference(RuleExecutorInterface::class),
                new Reference(ConfigurationProviderInterface::class),
                new Reference(GlobalCollectorRunner::class),
                new Reference(DependencyGraphBuilder::class),
                new Reference(DelegatingLogger::class),
                new Reference(ProfilerHolder::class),
            ])
            ->setPublic(true);
        $container->setAlias(AnalysisPipelineInterface::class, AnalysisPipeline::class)
            ->setPublic(true);
    }

    private function registerFormatters(ContainerBuilder $container): void
    {
        $srcDir = \dirname(__DIR__, 2); // src/
        $loader = new PhpFileLoader($container, new FileLocator($srcDir));

        // Auto-register all formatters from src/Reporting/Formatter/*
        // Classes implementing FormatterInterface will be auto-tagged via registerForAutoconfiguration
        $prototype = (new Definition())->setAutoconfigured(true)->setAutowired(true);
        $loader->registerClasses(
            $prototype,
            'AiMessDetector\\Reporting\\Formatter\\',
            $srcDir . '/Reporting/Formatter/*',
            $srcDir . '/Reporting/Formatter/{*Interface.php,FormatterRegistry.php}',
        );

        // FormatterRegistry will be populated by compiler pass
        $container->register(FormatterRegistry::class)
            ->setArguments([[]]);

        $container->setAlias(FormatterRegistryInterface::class, FormatterRegistry::class)
            ->setPublic(true);
    }

    private function registerBaseline(ContainerBuilder $container): void
    {
        $srcDir = \dirname(__DIR__, 2); // src/
        $loader = new PhpFileLoader($container, new FileLocator($srcDir));

        // Auto-register all baseline services from src/Baseline/*
        // Excludes: Value Objects (Baseline, BaselineEntry, Suppression)
        $prototype = (new Definition())->setAutoconfigured(true)->setAutowired(true);
        $loader->registerClasses(
            $prototype,
            'AiMessDetector\\Baseline\\',
            $srcDir . '/Baseline/*',
            $srcDir . '/Baseline/{Baseline.php,BaselineEntry.php,Suppression/Suppression.php}',
        );
    }

    private function registerCli(ContainerBuilder $container): void
    {
        // ConfigLoader
        $container->register(YamlConfigLoader::class);
        $container->setAlias(ConfigLoaderInterface::class, YamlConfigLoader::class);

        // AnalyzeCommand with all dependencies injected
        $container->register(AnalyzeCommand::class)
            ->setArguments([
                new Reference(RuleRegistryInterface::class),
                new Reference(AnalysisPipelineInterface::class),
                new Reference(FormatterRegistryInterface::class),
                new Reference(CacheFactory::class),
                new Reference(ConfigurationProviderInterface::class),
                new Reference(RuleOptionsFactory::class),
                new Reference(BaselineLoader::class),
                new Reference(BaselineWriter::class),
                new Reference(BaselineGenerator::class),
                new Reference(ViolationHasher::class),
                new Reference(SuppressionFilter::class),
                new Reference(LoggerFactory::class),
                new Reference(LoggerHolder::class),
                new Reference(ProgressReporterHolder::class),
                new Reference(ProfilerHolder::class),
                new Reference(ConfigurationPipeline::class),
            ])
            ->setPublic(true);

        // BaselineCleanupCommand
        $container->register(BaselineCleanupCommand::class)
            ->setArguments([
                new Reference(BaselineLoader::class),
                new Reference(BaselineWriter::class),
            ])
            ->setPublic(true);

        // HookInstallCommand (no dependencies)
        $container->register(HookInstallCommand::class)
            ->setPublic(true);

        // HookUninstallCommand (no dependencies)
        $container->register(HookUninstallCommand::class)
            ->setPublic(true);

        // HookStatusCommand (no dependencies)
        $container->register(HookStatusCommand::class)
            ->setPublic(true);

        // GraphExportCommand
        // Note: DependencyGraphBuilder is already registered in registerAnalysis()
        $container->register(GraphExportCommand::class)
            ->setArguments([
                new Reference(FileDiscoveryInterface::class),
                new Reference(FileParserInterface::class),
                new Reference(DependencyVisitor::class),
                new Reference(DependencyGraphBuilder::class),
                new Reference(DelegatingLogger::class),
            ])
            ->setPublic(true);
    }
}
