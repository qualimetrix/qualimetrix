<?php

declare(strict_types=1);

namespace AiMessDetector\Infrastructure\Console;

use AiMessDetector\Configuration\AnalysisConfiguration;
use AiMessDetector\Configuration\ComputedMetricsConfigResolver;
use AiMessDetector\Configuration\ConfigurationProviderInterface;
use AiMessDetector\Configuration\Pipeline\ResolvedConfiguration;
use AiMessDetector\Configuration\RuleOptionsFactory;
use AiMessDetector\Configuration\RuleOptionsParserFactory;
use AiMessDetector\Core\ComputedMetric\ComputedMetricDefinitionHolder;
use AiMessDetector\Core\Profiler\ProfilerHolder;
use AiMessDetector\Core\Progress\NullProgressReporter;
use AiMessDetector\Infrastructure\Cache\CacheFactory;
use AiMessDetector\Infrastructure\Console\Progress\ConsoleProgressBar;
use AiMessDetector\Infrastructure\Console\Progress\ProgressReporterHolder;
use AiMessDetector\Infrastructure\Logging\LoggerFactory;
use AiMessDetector\Infrastructure\Logging\LoggerHolder;
use AiMessDetector\Infrastructure\Profiler\Profiler;
use AiMessDetector\Infrastructure\Rule\RuleRegistryInterface;
use Psr\Log\LogLevel;
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
        private readonly RuleOptionsFactory $ruleOptionsFactory,
        private readonly RuleRegistryInterface $ruleRegistry,
        private readonly CacheFactory $cacheFactory,
        private readonly ComputedMetricsConfigResolver $computedMetricsResolver,
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
        $this->ruleOptionsFactory->resetCliOptions();
        $this->cacheFactory->reset();
        ComputedMetricDefinitionHolder::reset();

        $this->configureLogger($input, $output);
        $this->configureProgressReporter($input, $output);
        $this->configureProfiler($input);

        // Create RuleOptionsParser for CLI rule options
        $ruleOptionsParserFactory = new RuleOptionsParserFactory();
        $ruleOptionsParser = $ruleOptionsParserFactory->createFromClasses($this->ruleRegistry->getClasses());
        $cliParser = new CliOptionsParser($ruleOptionsParser);

        // Parse rule options from CLI
        $cliRuleOptions = $cliParser->parseRuleOptions($input);

        // Set config file and CLI options separately in the factory,
        // preserving the 3-layer merge: defaults → config file → CLI
        $this->ruleOptionsFactory->setConfigFileOptions($resolved->ruleOptions);
        foreach ($cliRuleOptions as $ruleName => $options) {
            $this->ruleOptionsFactory->setCliOptions($ruleName, $options);
        }

        // For ConfigurationHolder, provide the merged view
        $ruleOptions = array_replace_recursive($resolved->ruleOptions, $cliRuleOptions);

        // Configure runtime providers
        $this->configureRuntime($resolved->analysis, $ruleOptions);

        // Resolve computed metrics definitions and store in holder
        $definitions = $this->computedMetricsResolver->resolve($resolved->computedMetrics);
        ComputedMetricDefinitionHolder::setDefinitions($definitions);
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
