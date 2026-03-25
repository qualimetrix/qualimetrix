<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console;

use Psr\Log\LogLevel;
use Qualimetrix\Configuration\AnalysisConfiguration;
use Qualimetrix\Configuration\ComputedMetricsConfigResolver;
use Qualimetrix\Configuration\ConfigurationProviderInterface;
use Qualimetrix\Configuration\Pipeline\ResolvedConfiguration;
use Qualimetrix\Configuration\RuleOptionsFactory;
use Qualimetrix\Configuration\RuleOptionsParserFactory;
use Qualimetrix\Core\ComputedMetric\ComputedMetricDefinition;
use Qualimetrix\Core\ComputedMetric\ComputedMetricDefinitionHolder;
use Qualimetrix\Core\Profiler\ProfilerHolder;
use Qualimetrix\Core\Progress\NullProgressReporter;
use Qualimetrix\Infrastructure\Cache\CacheFactory;
use Qualimetrix\Infrastructure\Console\Progress\ConsoleProgressBar;
use Qualimetrix\Infrastructure\Console\Progress\ProgressReporterHolder;
use Qualimetrix\Infrastructure\Logging\LoggerFactory;
use Qualimetrix\Infrastructure\Logging\LoggerHolder;
use Qualimetrix\Infrastructure\Profiler\Profiler;
use Qualimetrix\Infrastructure\Rule\RuleRegistryInterface;
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
        $this->ruleOptionsFactory->getExclusionProvider()->reset();
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

        // Resolve computed metrics definitions, apply exclude-health, and store in holder
        $definitions = $this->computedMetricsResolver->resolve($resolved->computedMetrics);
        $definitions = $this->applyExcludeHealth($definitions, $resolved->analysis->excludeHealth);
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

    /**
     * Filters out excluded health dimensions and rebuilds health.overall formula
     * with normalized weights when dimensions are excluded.
     *
     * @param list<ComputedMetricDefinition> $definitions
     * @param list<string> $excludedDimensions
     *
     * @return list<ComputedMetricDefinition>
     */
    private function applyExcludeHealth(array $definitions, array $excludedDimensions): array
    {
        if ($excludedDimensions === []) {
            return $definitions;
        }

        // Normalize dimension names (allow both "typing" and "health.typing")
        $excludedNames = array_map(
            static fn(string $dim): string => str_starts_with($dim, 'health.') ? $dim : 'health.' . $dim,
            $excludedDimensions,
        );
        $excludedSet = array_flip($excludedNames);

        // Validate dimension names — warn on unknown
        $knownDimensions = [];
        foreach ($definitions as $definition) {
            if (str_starts_with($definition->name, 'health.') && $definition->name !== 'health.overall') {
                $knownDimensions[$definition->name] = true;
            }
        }

        foreach ($excludedNames as $name) {
            if ($name !== 'health.overall' && !isset($knownDimensions[$name])) {
                $this->loggerHolder->getLogger()->warning('Unknown health dimension in --exclude-health: {dimension}. Known dimensions: {known}', [
                    'dimension' => $name,
                    'known' => implode(', ', array_keys($knownDimensions)),
                ]);
            }
        }

        // Filter out excluded dimensions
        $filtered = [];
        $overallIndex = null;

        foreach ($definitions as $definition) {
            if (isset($excludedSet[$definition->name])) {
                continue;
            }

            if ($definition->name === 'health.overall') {
                $overallIndex = \count($filtered);
            }

            $filtered[] = $definition;
        }

        // Rebuild health.overall formula with normalized weights if some dimensions were excluded
        if ($overallIndex !== null) {
            $rebuilt = $this->rebuildOverallFormula($filtered[$overallIndex], $excludedSet);
            if ($rebuilt !== null) {
                $filtered[$overallIndex] = $rebuilt;
            } else {
                // All sub-dimensions excluded — remove health.overall entirely
                unset($filtered[$overallIndex]);
            }
        }

        return array_values($filtered);
    }

    /**
     * Rebuilds the health.overall formula by removing excluded dimensions
     * and normalizing remaining weights proportionally.
     *
     * @param array<string, int> $excludedSet
     */
    /**
     * @param array<string, int> $excludedSet
     */
    private function rebuildOverallFormula(ComputedMetricDefinition $overall, array $excludedSet): ?ComputedMetricDefinition
    {
        $formulas = $overall->formulas;
        $allEmpty = true;

        foreach ($formulas as $level => $formula) {
            $weights = $this->parseWeightsFromFormula($formula);
            $rebuilt = $this->buildWeightedFormula($weights, $excludedSet);

            if ($rebuilt !== null) {
                $formulas[$level] = $rebuilt;
                $allEmpty = false;
            } else {
                unset($formulas[$level]);
            }
        }

        if ($allEmpty) {
            return null;
        }

        return new ComputedMetricDefinition(
            name: $overall->name,
            formulas: $formulas,
            description: $overall->description,
            levels: $overall->levels,
            inverted: $overall->inverted,
            warningThreshold: $overall->warningThreshold,
            errorThreshold: $overall->errorThreshold,
        );
    }

    /**
     * Parses dimension weights from a health.overall formula string.
     *
     * Expected pattern: `(health__dimension ?? 75) * 0.25`
     *
     * @return array<string, float> dimension name => weight
     */
    private function parseWeightsFromFormula(string $formula): array
    {
        $weights = [];

        // Match patterns like: (health__complexity ?? 75) * 0.30
        if (preg_match_all('/\((\w+)\s*\?\?\s*\d+\)\s*\*\s*([\d.]+)/', $formula, $matches, \PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $varName = str_replace('__', '.', $match[1]);
                $weights[$varName] = (float) $match[2];
            }
        }

        return $weights;
    }

    /**
     * Builds a weighted formula string with normalized weights after exclusions.
     *
     * @param array<string, float> $weights
     * @param array<string, int> $excludedSet
     */
    private function buildWeightedFormula(array $weights, array $excludedSet): ?string
    {
        // Filter out excluded dimensions
        $remaining = [];
        foreach ($weights as $dim => $weight) {
            if (!isset($excludedSet[$dim])) {
                $remaining[$dim] = $weight;
            }
        }

        if ($remaining === []) {
            return null;
        }

        // Normalize weights to sum to 1.0
        $totalWeight = array_sum($remaining);
        $terms = [];

        foreach ($remaining as $dim => $weight) {
            $normalizedWeight = round($weight / $totalWeight, 4);
            $varName = str_replace('.', '__', $dim);
            $terms[] = \sprintf('(%s ?? 75) * %s', $varName, $normalizedWeight);
        }

        return \sprintf('clamp(%s, 0, 100)', implode(' + ', $terms));
    }
}
