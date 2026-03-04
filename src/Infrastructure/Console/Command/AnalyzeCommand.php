<?php

declare(strict_types=1);

namespace AiMessDetector\Infrastructure\Console\Command;

use AiMessDetector\Analysis\Discovery\FinderFileDiscovery;
use AiMessDetector\Analysis\Pipeline\AnalysisPipelineInterface;
use AiMessDetector\Baseline\BaselineGenerator;
use AiMessDetector\Baseline\BaselineLoader;
use AiMessDetector\Baseline\BaselineWriter;
use AiMessDetector\Baseline\Filter\BaselineFilter;
use AiMessDetector\Baseline\Suppression\SuppressionFilter;
use AiMessDetector\Baseline\ViolationHasher;
use AiMessDetector\Configuration\AnalysisConfiguration;
use AiMessDetector\Configuration\ConfigurationProviderInterface;
use AiMessDetector\Configuration\Exception\ConfigLoadException;
use AiMessDetector\Configuration\Pipeline\ConfigurationContext;
use AiMessDetector\Configuration\Pipeline\ConfigurationPipeline;
use AiMessDetector\Configuration\Pipeline\ResolvedConfiguration;
use AiMessDetector\Configuration\RuleOptionsFactory;
use AiMessDetector\Configuration\RuleOptionsParserFactory;
use AiMessDetector\Core\Profiler\ProfilerHolder;
use AiMessDetector\Core\Progress\NullProgressReporter;
use AiMessDetector\Infrastructure\Cache\CacheFactory;
use AiMessDetector\Infrastructure\Console\CliOptionsParser;
use AiMessDetector\Infrastructure\Console\OutputHelper;
use AiMessDetector\Infrastructure\Console\Progress\ConsoleProgressBar;
use AiMessDetector\Infrastructure\Console\Progress\ProgressReporterHolder;
use AiMessDetector\Infrastructure\Git\GitClient;
use AiMessDetector\Infrastructure\Git\GitFileDiscovery;
use AiMessDetector\Infrastructure\Git\GitScope;
use AiMessDetector\Infrastructure\Git\GitScopeFilter;
use AiMessDetector\Infrastructure\Git\GitScopeParser;
use AiMessDetector\Infrastructure\Logging\LoggerFactory;
use AiMessDetector\Infrastructure\Logging\LoggerHolder;
use AiMessDetector\Infrastructure\Profiler\Profiler;
use AiMessDetector\Infrastructure\Rule\Exception\ConflictingCliAliasException;
use AiMessDetector\Infrastructure\Rule\RuleRegistryInterface;
use AiMessDetector\Reporting\Formatter\FormatterRegistryInterface;
use AiMessDetector\Reporting\ReportBuilder;
use InvalidArgumentException;
use Psr\Log\LogLevel;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

#[AsCommand(
    name: 'analyze',
    description: 'Analyze PHP code for complexity and structural issues',
)]
final class AnalyzeCommand extends Command
{
    public function __construct(
        private readonly RuleRegistryInterface $ruleRegistry,
        private readonly AnalysisPipelineInterface $analyzer,
        private readonly FormatterRegistryInterface $formatterRegistry,
        private readonly CacheFactory $cacheFactory,
        private readonly ConfigurationProviderInterface $configurationProvider,
        private readonly RuleOptionsFactory $ruleOptionsFactory,
        private readonly BaselineLoader $baselineLoader,
        private readonly BaselineWriter $baselineWriter,
        private readonly BaselineGenerator $baselineGenerator,
        private readonly ViolationHasher $violationHasher,
        private readonly SuppressionFilter $suppressionFilter,
        private readonly LoggerFactory $loggerFactory,
        private readonly LoggerHolder $loggerHolder,
        private readonly ProgressReporterHolder $progressReporterHolder,
        private readonly ProfilerHolder $profilerHolder,
        private readonly ConfigurationPipeline $configurationPipeline,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument(
                'paths',
                InputArgument::OPTIONAL | InputArgument::IS_ARRAY,
                'Paths to analyze [default: auto-detect from composer.json]',
                [],
            )
            ->addOption(
                'exclude',
                null,
                InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                'Directories to exclude (can be repeated)',
                [],
            )
            ->addOption(
                'config',
                'c',
                InputOption::VALUE_REQUIRED,
                'Path to configuration file',
            )
            ->addOption(
                'format',
                'f',
                InputOption::VALUE_REQUIRED,
                'Output format (text)',
                AnalysisConfiguration::DEFAULT_FORMAT,
            )
            ->addOption(
                'no-cache',
                null,
                InputOption::VALUE_NONE,
                'Disable caching',
            )
            ->addOption(
                'cache-dir',
                null,
                InputOption::VALUE_REQUIRED,
                'Cache directory',
                AnalysisConfiguration::DEFAULT_CACHE_DIR,
            )
            ->addOption(
                'clear-cache',
                null,
                InputOption::VALUE_NONE,
                'Clear cache before analysis',
            )
            ->addOption(
                'baseline',
                null,
                InputOption::VALUE_REQUIRED,
                'Path to baseline file for filtering known violations',
            )
            ->addOption(
                'generate-baseline',
                null,
                InputOption::VALUE_REQUIRED,
                'Generate baseline file with current violations',
            )
            ->addOption(
                'show-resolved',
                null,
                InputOption::VALUE_NONE,
                'Show count of violations resolved since baseline',
            )
            ->addOption(
                'baseline-ignore-stale',
                null,
                InputOption::VALUE_NONE,
                'Ignore stale baseline entries instead of failing',
            )
            ->addOption(
                'show-suppressed',
                null,
                InputOption::VALUE_NONE,
                'Show suppressed violations',
            )
            ->addOption(
                'no-suppression',
                null,
                InputOption::VALUE_NONE,
                'Ignore suppression tags',
            )
            ->addOption(
                'analyze',
                null,
                InputOption::VALUE_REQUIRED,
                'Scope of files to analyze (e.g., git:staged, git:main..HEAD)',
            )
            ->addOption(
                'report',
                null,
                InputOption::VALUE_REQUIRED,
                'Scope of violations to report (e.g., git:main..HEAD)',
            )
            ->addOption(
                'report-strict',
                null,
                InputOption::VALUE_NONE,
                'Only show violations exactly in changed files (exclude parent namespaces)',
            )
            ->addOption(
                'staged',
                null,
                InputOption::VALUE_NONE,
                'Shortcut for --analyze=git:staged (analyze only staged files)',
            )
            ->addOption(
                'diff',
                null,
                InputOption::VALUE_REQUIRED,
                'Shortcut for --report=git:<ref>..HEAD (show only violations in changed files)',
            )
            ->addOption(
                'workers',
                'w',
                InputOption::VALUE_REQUIRED,
                'Number of parallel workers (0 to disable, default: auto-detect)',
            )
            ->addOption(
                'log-file',
                null,
                InputOption::VALUE_REQUIRED,
                'Write debug log to file',
            )
            ->addOption(
                'log-level',
                null,
                InputOption::VALUE_REQUIRED,
                'Minimum log level (debug, info, warning, error)',
                'info',
            )
            ->addOption(
                'no-progress',
                null,
                InputOption::VALUE_NONE,
                'Disable progress bar',
            )
            ->addOption(
                'profile',
                null,
                InputOption::VALUE_OPTIONAL,
                'Enable profiling and export to file (or show summary if no file specified)',
                false,
            )
            ->addOption(
                'profile-format',
                null,
                InputOption::VALUE_REQUIRED,
                'Profile export format (json or chrome-tracing)',
                'json',
            );

        // Dynamic rule options from registry
        foreach ($this->ruleRegistry->getAllCliAliases() as $alias => $info) {
            $this->addOption(
                $alias,
                null,
                InputOption::VALUE_REQUIRED,
                \sprintf('[%s] %s', $info['rule'], $info['option']),
            );
        }

        // Generic rule options
        $this
            ->addOption(
                'disable-rule',
                null,
                InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                'Disable a rule or level (e.g., complexity, complexity.class)',
            )
            ->addOption(
                'only-rule',
                null,
                InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                'Run only specified rules or levels (e.g., complexity, complexity.method)',
            )
            ->addOption(
                'rule-opt',
                null,
                InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                'Rule-specific option (format: rule-name:option=value)',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            return $this->doExecute($input, $output);
        } catch (ConflictingCliAliasException $e) {
            $output->writeln(\sprintf(
                '<error>CLI alias conflict: "%s" is used by both "%s" and "%s" rules</error>',
                $e->alias,
                $e->firstRule,
                $e->secondRule,
            ));

            return self::FAILURE;
        } catch (ConfigLoadException $e) {
            $output->writeln(\sprintf(
                '<error>Configuration error: %s</error>',
                $e->getMessage(),
            ));

            return self::FAILURE;
        } catch (InvalidArgumentException $e) {
            $output->writeln(\sprintf('<error>%s</error>', $e->getMessage()));

            return self::FAILURE;
        } catch (Throwable $e) {
            $output->writeln(\sprintf(
                '<error>Unexpected error: %s</error>',
                $e->getMessage(),
            ));

            if ($output->isVerbose()) {
                $output->writeln('');
                $output->writeln('<comment>Stack trace:</comment>');
                $output->writeln($e->getTraceAsString());
            }

            return self::FAILURE;
        }
    }

    /**
     * Executes the analysis.
     *
     * Separated from execute() to keep error handling at the top level.
     */
    private function doExecute(InputInterface $input, OutputInterface $output): int
    {
        // Resolve configuration through pipeline
        $resolved = $this->resolveConfiguration($input);

        // Configure runtime using resolved config
        $this->configureRuntimeFromResolved($resolved, $input, $output);

        $this->clearCacheIfRequested($input, $output);

        $filesToAnalyze = $this->resolveFilesToAnalyzeFromResolved($input, $resolved);

        $result = $this->runAnalysis($filesToAnalyze['paths'], $filesToAnalyze['fileDiscovery']);

        $filteredViolations = $this->applyFilters(
            $result,
            $input,
            $output,
            $filesToAnalyze['gitClient'],
            $filesToAnalyze['analyzeScope'],
            $filesToAnalyze['reportScope'],
        );

        $this->generateBaselineIfRequested($result->violations, $input, $output);

        $exitCode = $this->outputResults($filteredViolations, $result, $input, $output);

        $this->outputProfile($input, $output);

        return $exitCode;
    }

    /**
     * Resolves configuration using the pipeline.
     */
    private function resolveConfiguration(InputInterface $input): ResolvedConfiguration
    {
        $context = new ConfigurationContext($input, getcwd() ?: '.');
        return $this->configurationPipeline->resolve($context);
    }

    /**
     * Configures runtime from resolved configuration.
     */
    private function configureRuntimeFromResolved(
        ResolvedConfiguration $resolved,
        InputInterface $input,
        OutputInterface $output,
    ): void {
        // Configure logger
        $this->configureLogger($input, $output);

        // Configure progress reporter
        $this->configureProgressReporter($input, $output);

        // Configure profiler
        $this->configureProfiler($input);

        // Create RuleOptionsParser for CLI rule options
        $ruleOptionsParserFactory = new RuleOptionsParserFactory();
        $ruleOptionsParser = $ruleOptionsParserFactory->createFromClasses($this->ruleRegistry->getClasses());
        $cliParser = new CliOptionsParser($ruleOptionsParser);

        // Parse rule options from CLI and merge with config file options
        $cliRuleOptions = $cliParser->parseRuleOptions($input);
        $ruleOptions = array_merge($resolved->ruleOptions, $cliRuleOptions);

        // Configure runtime providers
        $this->configureRuntime($resolved->analysis, $ruleOptions);
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

        // Update RuleOptionsFactory with CLI options
        foreach ($ruleOptions as $ruleName => $options) {
            $this->ruleOptionsFactory->setCliOptions($ruleName, $options);
        }
    }

    /**
     * Resolves the analyze scope from CLI options.
     *
     * Returns null if no analyze scope is specified (full analysis).
     */
    private function resolveAnalyzeScope(InputInterface $input): ?GitScope
    {
        // Check --staged shortcut first
        if ($input->getOption('staged')) {
            return new GitScope('staged');
        }

        // Check --analyze option
        $analyze = $input->getOption('analyze');
        if (\is_string($analyze) && $analyze !== '') {
            $parser = new GitScopeParser();
            $scope = $parser->parse($analyze);

            if ($scope === null) {
                throw new InvalidArgumentException(
                    \sprintf('Invalid analyze scope: %s. Expected format: git:<ref>', $analyze),
                );
            }

            return $scope;
        }

        return null;
    }

    /**
     * Resolves the report scope from CLI options.
     *
     * Returns null if no report scope is specified.
     * If analyze scope is set but report scope is not, report scope equals analyze scope (implicit).
     */
    private function resolveReportScope(InputInterface $input, ?GitScope $analyzeScope): ?GitScope
    {
        // Check --diff shortcut first
        $diff = $input->getOption('diff');
        if (\is_string($diff) && $diff !== '') {
            return new GitScope(\sprintf('%s..HEAD', $diff));
        }

        // Check --report option
        $report = $input->getOption('report');
        if (\is_string($report) && $report !== '') {
            $parser = new GitScopeParser();
            $scope = $parser->parse($report);

            if ($scope === null) {
                throw new InvalidArgumentException(
                    \sprintf('Invalid report scope: %s. Expected format: git:<ref>', $report),
                );
            }

            return $scope;
        }

        // Implicit: if analyze scope is set, report scope equals analyze scope
        return $analyzeScope;
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
     * Clears cache if requested via CLI option.
     */
    private function clearCacheIfRequested(InputInterface $input, OutputInterface $output): void
    {
        if ($input->getOption('clear-cache')) {
            $cache = $this->cacheFactory->create();
            $cache->clear();
            $output->writeln('<info>Cache cleared.</info>');
        }
    }

    /**
     * Resolves files using paths from ResolvedConfiguration.
     *
     * @return array{paths: list<string>, fileDiscovery: FinderFileDiscovery|GitFileDiscovery|null, gitClient: ?GitClient, analyzeScope: ?GitScope, reportScope: ?GitScope}
     */
    private function resolveFilesToAnalyzeFromResolved(
        InputInterface $input,
        ResolvedConfiguration $resolved,
    ): array {
        // Use paths from resolved configuration
        $paths = $resolved->paths->paths;

        // Resolve Git scopes
        $analyzeScope = $this->resolveAnalyzeScope($input);
        $reportScope = $this->resolveReportScope($input, $analyzeScope);

        // Create GitClient if needed
        $gitClient = null;
        if ($analyzeScope !== null || $reportScope !== null) {
            $gitClient = new GitClient(getcwd() ?: '.');
        }

        // Create file discovery with excludes from configuration
        $fileDiscovery = null;
        if ($analyzeScope !== null && $gitClient !== null) {
            // Git scope analysis uses GitFileDiscovery
            $fileDiscovery = new GitFileDiscovery($gitClient, $analyzeScope);
        } else {
            // Regular analysis uses FinderFileDiscovery with excludes from config
            $fileDiscovery = new FinderFileDiscovery($resolved->paths->excludes);
        }

        return [
            'paths' => $paths,
            'fileDiscovery' => $fileDiscovery,
            'gitClient' => $gitClient,
            'analyzeScope' => $analyzeScope,
            'reportScope' => $reportScope,
        ];
    }

    /**
     * Runs the analysis on specified paths.
     *
     * @param list<string> $paths
     */
    private function runAnalysis(array $paths, FinderFileDiscovery|GitFileDiscovery|null $fileDiscovery): \AiMessDetector\Analysis\Pipeline\AnalysisResult
    {
        return $this->analyzer->analyze($paths, $fileDiscovery);
    }

    /**
     * Applies all filters to violations (baseline, suppression, git scope).
     *
     * @return list<\AiMessDetector\Core\Violation\Violation>
     */
    private function applyFilters(
        \AiMessDetector\Analysis\Pipeline\AnalysisResult $result,
        InputInterface $input,
        OutputInterface $output,
        ?GitClient $gitClient,
        ?GitScope $analyzeScope,
        ?GitScope $reportScope,
    ): array {
        $violations = $this->applyBaselineFilter($result->violations, $result, $input, $output);
        $violations = $this->applySuppressionFilter($violations, $input, $output);
        $violations = $this->applyGitScopeFilter($violations, $gitClient, $reportScope, $analyzeScope, $input, $output);

        return $violations;
    }

    /**
     * Applies baseline filter to violations.
     *
     * @param list<\AiMessDetector\Core\Violation\Violation> $violations
     *
     * @return list<\AiMessDetector\Core\Violation\Violation>
     */
    private function applyBaselineFilter(
        array $violations,
        \AiMessDetector\Analysis\Pipeline\AnalysisResult $result,
        InputInterface $input,
        OutputInterface $output,
    ): array {
        $baselinePath = $input->getOption('baseline');
        if (!\is_string($baselinePath) || $baselinePath === '') {
            return $violations;
        }

        $baseline = $this->baselineLoader->load($baselinePath);

        // Check for stale entries (files that no longer exist)
        if (!$this->handleStaleBaselineEntries($baseline, $violations, $baselinePath, $input, $output)) {
            throw new InvalidArgumentException('Baseline contains stale entries');
        }

        // Apply baseline filter
        $baselineFilter = new BaselineFilter($baseline, $this->violationHasher);
        $violations = array_values(array_filter(
            $violations,
            fn($v) => $baselineFilter->shouldInclude($v),
        ));

        // Show resolved violations if requested
        if ($input->getOption('show-resolved')) {
            $this->showResolvedViolations($baselineFilter, $result->violations, $output);
        }

        return $violations;
    }

    /**
     * Handles stale baseline entries (symbols that no longer exist).
     *
     * @param list<\AiMessDetector\Core\Violation\Violation> $violations
     *
     * @return bool True if processing should continue, false if should fail
     */
    private function handleStaleBaselineEntries(
        \AiMessDetector\Baseline\Baseline $baseline,
        array $violations,
        string $baselinePath,
        InputInterface $input,
        OutputInterface $output,
    ): bool {
        $existingCanonicals = array_map(
            fn($v) => $v->symbolPath->toCanonical(),
            $violations,
        );
        $staleKeys = $baseline->getStaleKeys(array_values(array_unique($existingCanonicals)));

        if ($staleKeys === []) {
            return true;
        }

        $staleCount = 0;
        foreach ($staleKeys as $key) {
            $staleCount += \count($baseline->entries[$key] ?? []);
        }

        if ($input->getOption('baseline-ignore-stale')) {
            $output->writeln(\sprintf(
                '<comment>Warning: Baseline contains %d stale entries (symbols no longer exist)</comment>',
                $staleCount,
            ));
            $output->writeln(\sprintf(
                '<comment>Run `bin/aimd baseline:cleanup %s` to remove them.</comment>',
                $baselinePath,
            ));

            return true;
        }

        $output->writeln(\sprintf(
            '<error>Error: Baseline contains %d stale entries (symbols no longer exist):</error>',
            $staleCount,
        ));
        foreach ($staleKeys as $key) {
            $count = \count($baseline->entries[$key] ?? []);
            $output->writeln(\sprintf('  - %s (%d entries)', $key, $count));
        }
        $output->writeln('');
        $output->writeln(\sprintf('Run `bin/aimd baseline:cleanup %s` to remove stale entries.', $baselinePath));
        $output->writeln('Or use --baseline-ignore-stale to continue anyway.');

        return false;
    }

    /**
     * Shows count of resolved violations from baseline.
     *
     * @param list<\AiMessDetector\Core\Violation\Violation> $allViolations
     */
    private function showResolvedViolations(
        BaselineFilter $baselineFilter,
        array $allViolations,
        OutputInterface $output,
    ): void {
        $resolved = $baselineFilter->getResolvedFromBaseline($allViolations);
        $resolvedCount = array_sum(array_map(count(...), $resolved));

        if ($resolvedCount > 0) {
            $output->writeln(\sprintf(
                '<info>%d violations from baseline have been resolved!</info>',
                $resolvedCount,
            ));
        }
    }

    /**
     * Applies suppression filter to violations.
     *
     * @param list<\AiMessDetector\Core\Violation\Violation> $violations
     *
     * @return list<\AiMessDetector\Core\Violation\Violation>
     */
    private function applySuppressionFilter(
        array $violations,
        InputInterface $input,
        OutputInterface $output,
    ): array {
        if ($input->getOption('no-suppression')) {
            return $violations;
        }

        $beforeSuppression = \count($violations);
        $violations = array_values(array_filter(
            $violations,
            fn($v) => $this->suppressionFilter->shouldInclude($v),
        ));
        $suppressedCount = $beforeSuppression - \count($violations);

        if ($input->getOption('show-suppressed') && $suppressedCount > 0) {
            $output->writeln(\sprintf(
                '<info>%d violations were suppressed by @aimd-ignore tags</info>',
                $suppressedCount,
            ));
        }

        return $violations;
    }

    /**
     * Applies Git scope filter for reporting.
     *
     * @param list<\AiMessDetector\Core\Violation\Violation> $violations
     *
     * @return list<\AiMessDetector\Core\Violation\Violation>
     */
    private function applyGitScopeFilter(
        array $violations,
        ?GitClient $gitClient,
        ?GitScope $reportScope,
        ?GitScope $analyzeScope,
        InputInterface $input,
        OutputInterface $output,
    ): array {
        if ($reportScope === null || $gitClient === null) {
            return $violations;
        }

        $strictMode = $input->getOption('report-strict');
        $gitFilter = new GitScopeFilter($gitClient, $reportScope, !$strictMode);

        $violations = array_values(array_filter(
            $violations,
            fn($v) => $gitFilter->shouldInclude($v),
        ));

        // Show warning about partial analysis if analyze scope was used
        if ($analyzeScope !== null && $violations !== []) {
            $output->writeln(
                '<comment>Note: Aggregated metrics not available in partial analysis mode.</comment>',
            );
        }

        return $violations;
    }

    /**
     * Generates baseline file if requested.
     *
     * @param list<\AiMessDetector\Core\Violation\Violation> $violations
     */
    private function generateBaselineIfRequested(
        array $violations,
        InputInterface $input,
        OutputInterface $output,
    ): void {
        $generateBaselinePath = $input->getOption('generate-baseline');
        if (!\is_string($generateBaselinePath) || $generateBaselinePath === '') {
            return;
        }

        $baseline = $this->baselineGenerator->generate($violations);
        $this->baselineWriter->write($baseline, $generateBaselinePath);

        $output->writeln(\sprintf(
            '<info>Baseline with %d violations written to %s</info>',
            \count($violations),
            $generateBaselinePath,
        ));
    }

    /**
     * Outputs formatted results and returns exit code.
     *
     * @param list<\AiMessDetector\Core\Violation\Violation> $violations
     */
    private function outputResults(
        array $violations,
        \AiMessDetector\Analysis\Pipeline\AnalysisResult $result,
        InputInterface $input,
        OutputInterface $output,
    ): int {
        /** @var string $format */
        $format = $input->getOption('format') ?? AnalysisConfiguration::DEFAULT_FORMAT;
        $formatter = $this->formatterRegistry->get($format);

        // Build and output report with filtered violations
        $report = ReportBuilder::create()
            ->addViolations($violations)
            ->filesAnalyzed($result->filesAnalyzed)
            ->filesSkipped($result->filesSkipped)
            ->duration($result->duration)
            ->build();
        OutputHelper::write($output, $formatter->format($report));

        return $this->determineExitCode($violations);
    }

    /**
     * Determines exit code based on violation severity.
     *
     * @param list<\AiMessDetector\Core\Violation\Violation> $violations
     */
    private function determineExitCode(array $violations): int
    {
        $hasErrors = false;
        $hasWarnings = false;

        foreach ($violations as $violation) {
            if ($violation->severity === \AiMessDetector\Core\Violation\Severity::Error) {
                $hasErrors = true;
                break;
            }
            if ($violation->severity === \AiMessDetector\Core\Violation\Severity::Warning) {
                $hasWarnings = true;
            }
        }

        if ($hasErrors) {
            return 2;
        }

        if ($hasWarnings) {
            return 1;
        }

        return 0;
    }

    /**
     * Outputs profiling results if profiling was enabled.
     */
    private function outputProfile(InputInterface $input, OutputInterface $output): void
    {
        $profiler = $this->profilerHolder->get();

        if (!$profiler->isEnabled()) {
            return;
        }

        $profileOption = $input->getOption('profile');

        // If --profile without value, output summary to stderr
        if ($profileOption === null) {
            $summary = $this->formatProfileSummary($profiler->getSummary());
            $output->writeln('', OutputInterface::OUTPUT_NORMAL | OutputInterface::VERBOSITY_NORMAL);
            $output->writeln($summary, OutputInterface::OUTPUT_NORMAL | OutputInterface::VERBOSITY_NORMAL);

            return;
        }

        // Export to file
        /** @var string $formatOption */
        $formatOption = $input->getOption('profile-format') ?? 'json';

        // Validate format
        if (!\in_array($formatOption, ['json', 'chrome-tracing'], true)) {
            $output->writeln(
                \sprintf('<error>Invalid profile format: %s. Valid formats: json, chrome-tracing</error>', $formatOption),
                OutputInterface::OUTPUT_NORMAL | OutputInterface::VERBOSITY_NORMAL,
            );

            return;
        }

        /** @var 'json'|'chrome-tracing' $format */
        $format = $formatOption;

        $profileData = $profiler->export($format);

        // Atomic write: write to temp file first, then rename
        $tmpFile = $profileOption . '.tmp.' . getmypid();
        file_put_contents($tmpFile, $profileData);
        rename($tmpFile, $profileOption);

        $output->writeln(
            \sprintf('<info>Profile exported to %s</info>', $profileOption),
            OutputInterface::OUTPUT_NORMAL | OutputInterface::VERBOSITY_NORMAL,
        );
    }

    /**
     * Formats profiling summary for console output.
     *
     * @param array<string, array{total: float, count: int, avg: float, memory: int}> $summary
     */
    private function formatProfileSummary(array $summary): string
    {
        if ($summary === []) {
            return '<comment>No profiling data available</comment>';
        }

        // Calculate total time
        $totalTime = 0.0;
        foreach ($summary as $stat) {
            $totalTime += $stat['total'];
        }

        // Sort by total time descending
        uasort($summary, fn($a, $b) => $b['total'] <=> $a['total']);

        $lines = ['<comment>Profile summary:</comment>'];

        foreach ($summary as $name => $stat) {
            $percentage = $totalTime > 0 ? ($stat['total'] / $totalTime) * 100 : 0;
            $memory = $this->formatBytes($stat['memory']);

            $lines[] = \sprintf(
                '  <info>%s</info>: %.3fs (%3.0f%%) | %s | %dx',
                str_pad($name, 15),
                $stat['total'] / 1000, // ms to s
                $percentage,
                str_pad($memory, 8),
                $stat['count'],
            );
        }

        // Add peak memory
        $peakMemory = memory_get_peak_usage(true);
        $lines[] = \sprintf('<comment>Peak memory:</comment> %s', $this->formatBytes($peakMemory));

        return implode("\n", $lines);
    }

    /**
     * Formats bytes to human-readable format.
     */
    private function formatBytes(int $bytes): string
    {
        if ($bytes < 0) {
            return \sprintf('%d B', $bytes);
        }

        $units = ['B', 'KB', 'MB', 'GB'];
        $bytes = max($bytes, 0);
        $pow = $bytes > 0 ? floor(log($bytes, 1024)) : 0;
        $pow = min($pow, \count($units) - 1);

        $bytes /= (1024 ** $pow);

        return \sprintf('%.1f %s', $bytes, $units[(int) $pow]);
    }
}
