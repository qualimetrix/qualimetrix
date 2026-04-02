<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console;

use Psr\Log\LogLevel;
use Qualimetrix\Configuration\AnalysisConfiguration;
use Qualimetrix\Configuration\ComputedMetricsConfigResolver;
use Qualimetrix\Configuration\ConfigurationProviderInterface;
use Qualimetrix\Configuration\HealthFormulaExcluder;
use Qualimetrix\Configuration\Pipeline\ResolvedConfiguration;
use Qualimetrix\Configuration\RuleOptionsParserFactory;
use Qualimetrix\Configuration\RuleOptionsRegistry;
use Qualimetrix\Core\ComputedMetric\ComputedMetricDefinitionHolder;
use Qualimetrix\Core\Coupling\FrameworkNamespaces;
use Qualimetrix\Core\Coupling\FrameworkNamespacesHolder;
use Qualimetrix\Core\Metric\CollectorConfigHolder;
use Qualimetrix\Core\Profiler\ProfilerHolder;
use Qualimetrix\Core\Progress\NullProgressReporter;
use Qualimetrix\Infrastructure\Cache\CacheFactory;
use Qualimetrix\Infrastructure\Console\Progress\ConsoleProgressBar;
use Qualimetrix\Infrastructure\Console\Progress\ProgressReporterHolder;
use Qualimetrix\Infrastructure\Logging\LoggerFactory;
use Qualimetrix\Infrastructure\Logging\LoggerHolder;
use Qualimetrix\Infrastructure\Profiler\Profiler;
use Qualimetrix\Infrastructure\Rule\RuleRegistryInterface;
use Qualimetrix\Rules\Structure\LcomOptions;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Configures runtime services (logger, progress reporter, profiler, rule options)
 * based on resolved configuration and CLI input.
 */
final class RuntimeConfigurator
{
    public function __construct(
        private readonly LoggerFactory $loggerFactory,
        private readonly LoggerHolder $loggerHolder,
        private readonly ProgressReporterHolder $progressReporterHolder,
        private readonly ProfilerHolder $profilerHolder,
        private readonly ConfigurationProviderInterface $configurationProvider,
        private readonly RuleOptionsRegistry $ruleOptionsRegistry,
        private readonly RuleRegistryInterface $ruleRegistry,
        private readonly CacheFactory $cacheFactory,
        private readonly ComputedMetricsConfigResolver $computedMetricsResolver,
        private readonly HealthFormulaExcluder $healthFormulaExcluder,
        private readonly FrameworkNamespacesHolder $frameworkNamespacesHolder,
    ) {}

    /**
     * Configures all runtime services from resolved configuration and CLI input.
     */
    public function configure(
        ResolvedConfiguration $resolved,
        InputInterface $input,
        OutputInterface $output,
    ): void {
        // Reset memoized state from previous run to prevent leaking
        $this->ruleOptionsRegistry->resetRuntimeState();
        $this->cacheFactory->reset();
        ComputedMetricDefinitionHolder::reset();
        CollectorConfigHolder::reset();
        $this->frameworkNamespacesHolder->reset();

        $this->configureLogger($input, $output);
        $this->configureMemoryLimit($resolved->analysis, $output);
        $this->configureProgressReporter($input, $output);
        $this->configureProfiler($input);

        // Create RuleOptionsParser for CLI rule options
        $ruleOptionsParserFactory = new RuleOptionsParserFactory();
        $ruleOptionsParser = $ruleOptionsParserFactory->createFromClasses($this->ruleRegistry->getClasses());
        $cliParser = new CliOptionsParser($ruleOptionsParser);

        // Parse rule options from CLI
        $cliRuleOptions = $cliParser->parseRuleOptions($input);

        // Set config file and CLI options separately in the registry,
        // preserving the 3-layer merge: defaults → config file → CLI
        $this->ruleOptionsRegistry->setConfigFileOptions($resolved->ruleOptions);
        foreach ($cliRuleOptions as $ruleName => $options) {
            $this->ruleOptionsRegistry->setCliOptions($ruleName, $options);
        }

        // For ConfigurationHolder, provide the merged view
        $ruleOptions = array_replace_recursive($resolved->ruleOptions, $cliRuleOptions);

        // Configure runtime providers
        $this->configureRuntime($resolved->analysis, $ruleOptions);

        // Extract collector-level config from rule options
        $this->configureCollectors($ruleOptions);

        // Set framework namespaces for CBO_APP/CE_FRAMEWORK metrics
        if ($resolved->analysis->frameworkNamespaces !== []) {
            $this->frameworkNamespacesHolder->set(
                new FrameworkNamespaces($resolved->analysis->frameworkNamespaces),
            );
        }

        // Resolve computed metrics definitions, apply exclude-health, and store in holder
        $definitions = $this->computedMetricsResolver->resolve($resolved->computedMetrics);
        $definitions = $this->healthFormulaExcluder->applyExcludeHealth($definitions, $resolved->analysis->excludeHealth);
        ComputedMetricDefinitionHolder::setDefinitions($definitions);
    }

    /**
     * Applies PHP memory limit from configuration.
     *
     * The default (512M) is set in DefaultsStage and can be overridden
     * via qmx.yaml or --memory-limit CLI option.
     */
    private function configureMemoryLimit(AnalysisConfiguration $config, OutputInterface $output): void
    {
        if ($config->memoryLimit === null) {
            return;
        }

        $result = ini_set('memory_limit', $config->memoryLimit);

        if ($result === false) {
            $output->writeln(\sprintf(
                '<comment>Warning: failed to set memory_limit to %s. ini_set() may be disabled.</comment>',
                $config->memoryLimit,
            ));
        }
    }

    /**
     * Configures logger based on CLI options.
     *
     * Creates appropriate logger using LoggerFactory and sets it in LoggerHolder
     * so that all components (Analyzer, PhpFileParser) can use it.
     */
    private function configureLogger(InputInterface $input, OutputInterface $output): void
    {
        // Get log file path and level from CLI options
        $logFile = $input->getOption('log-file');
        $logLevel = $input->getOption('log-level');

        // Validate log file path
        if (!\is_string($logFile) && $logFile !== null) {
            $logFile = null;
        }

        // Validate log level
        if (!\is_string($logLevel)) {
            $logLevel = LogLevel::INFO;
        }

        // Normalize log level
        $logLevel = strtolower($logLevel);
        $validLevels = ['debug', 'info', 'warning', 'error'];
        if (!\in_array($logLevel, $validLevels, true)) {
            $logLevel = LogLevel::INFO;
        }

        // Create logger
        $logger = $this->loggerFactory->create($output, $logFile, $logLevel);

        // Set logger in holder so all components can use it
        $this->loggerHolder->setLogger($logger);
    }

    /**
     * Configures progress reporter based on CLI options.
     *
     * Creates appropriate progress reporter and sets it in ProgressReporterHolder
     * so that Analyzer can report progress during analysis.
     */
    private function configureProgressReporter(InputInterface $input, OutputInterface $output): void
    {
        // Disable for non-TTY (CI, pipes)
        if (!$output->isDecorated()) {
            $this->progressReporterHolder->setReporter(new NullProgressReporter());

            return;
        }

        // Explicit disable
        if ($input->getOption('no-progress')) {
            $this->progressReporterHolder->setReporter(new NullProgressReporter());

            return;
        }

        // Disable for quiet mode
        if ($output->getVerbosity() === OutputInterface::VERBOSITY_QUIET) {
            $this->progressReporterHolder->setReporter(new NullProgressReporter());

            return;
        }

        // Use console progress bar
        $this->progressReporterHolder->setReporter(new ConsoleProgressBar($output));
    }

    /**
     * Configures profiler based on CLI options.
     */
    private function configureProfiler(InputInterface $input): void
    {
        $profileOption = $input->getOption('profile');

        // If --profile was not provided, profiler stays as NullProfiler (default)
        if ($profileOption === false) {
            return;
        }

        // Enable profiler if --profile or --profile=file was provided
        $this->profilerHolder->set(new Profiler());
    }

    /**
     * Extracts collector-level configuration from rule options.
     *
     * Some rule options affect metric calculation (not just thresholds),
     * so they must reach collectors via CollectorConfigHolder.
     *
     * @param array<string, array<string, mixed>> $ruleOptions
     */
    private function configureCollectors(array $ruleOptions): void
    {
        $lcomConfig = $ruleOptions['design.lcom'] ?? [];
        if ($lcomConfig !== []) {
            $options = LcomOptions::fromArray($lcomConfig);
            if ($options->excludeMethods !== null && $options->excludeMethods !== []) {
                CollectorConfigHolder::set(
                    CollectorConfigHolder::LCOM_EXCLUDE_METHODS,
                    $options->excludeMethods,
                );
            }
        }
    }

    /**
     * Configure runtime providers with merged configuration.
     *
     * This must be called BEFORE rules are accessed (they are lazy-loaded).
     *
     * @param array<string, array<string, mixed>> $ruleOptions
     */
    private function configureRuntime(AnalysisConfiguration $config, array $ruleOptions): void
    {
        // Update ConfigurationHolder with merged config
        $this->configurationProvider->setConfiguration($config);
        $this->configurationProvider->setRuleOptions($ruleOptions);
    }
}
