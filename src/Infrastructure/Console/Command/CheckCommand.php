<?php

declare(strict_types=1);

namespace AiMessDetector\Infrastructure\Console\Command;

use AiMessDetector\Analysis\Pipeline\AnalysisPipelineInterface;
use AiMessDetector\Configuration\Exception\ConfigLoadException;
use AiMessDetector\Configuration\Pipeline\ConfigurationContext;
use AiMessDetector\Configuration\Pipeline\ConfigurationPipeline;
use AiMessDetector\Configuration\Pipeline\ResolvedConfiguration;
use AiMessDetector\Infrastructure\Cache\CacheFactory;
use AiMessDetector\Infrastructure\Console\CheckCommandDefinition;
use AiMessDetector\Infrastructure\Console\GitScopeFilterConfig;
use AiMessDetector\Infrastructure\Console\ResultPresenter;
use AiMessDetector\Infrastructure\Console\RuntimeConfigurator;
use AiMessDetector\Infrastructure\Console\ViolationFilterOptions;
use AiMessDetector\Infrastructure\Console\ViolationFilterPipeline;
use AiMessDetector\Infrastructure\Console\ViolationFilterResult;
use AiMessDetector\Infrastructure\Git\GitScopeResolution;
use AiMessDetector\Infrastructure\Git\GitScopeResolver;
use AiMessDetector\Infrastructure\Rule\Exception\ConflictingCliAliasException;
use AiMessDetector\Infrastructure\Rule\RuleRegistryInterface;
use InvalidArgumentException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

#[AsCommand(
    name: 'check',
    description: 'Check PHP code for complexity and structural issues',
    aliases: ['analyze'],
)]
final class CheckCommand extends Command
{
    public function __construct(
        private readonly RuleRegistryInterface $ruleRegistry,
        private readonly AnalysisPipelineInterface $analyzer,
        private readonly CacheFactory $cacheFactory,
        private readonly ViolationFilterPipeline $violationFilterPipeline,
        private readonly ConfigurationPipeline $configurationPipeline,
        private readonly RuntimeConfigurator $runtimeConfigurator,
        private readonly ResultPresenter $resultPresenter,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        CheckCommandDefinition::addOptions($this, $this->ruleRegistry);
        $this->setHelp('Run <info>bin/aimd rules</info> to see all available rules and their CLI options.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->showDeprecationWarningIfNeeded($input, $output);

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
     * Shows a deprecation warning when the command is invoked via the 'analyze' alias.
     */
    private function showDeprecationWarningIfNeeded(InputInterface $input, OutputInterface $output): void
    {
        $firstArg = $input->getFirstArgument();
        if ($firstArg === 'analyze') {
            $output->writeln(
                '<comment>[DEPRECATED] The \'analyze\' command is deprecated, use \'check\' instead.</comment>',
            );
            $output->writeln('');
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
        $this->runtimeConfigurator->configure($resolved, $input, $output);

        $this->clearCacheIfRequested($input, $output);

        $this->validateWorkersOption($input);
        $this->warnAboutUnknownRules($resolved, $output);
        $this->warnAboutConflictingRuleFilters($resolved, $output);
        $this->logConfigSources($resolved, $output);

        $scopeResolution = (new GitScopeResolver())->resolve($input, $resolved);

        $pathErrors = $this->validatePaths($scopeResolution->paths);
        if ($pathErrors !== []) {
            foreach ($pathErrors as $error) {
                $output->writeln(\sprintf('<error>%s</error>', $error));
            }

            return self::FAILURE;
        }

        $result = $this->runAnalysis($scopeResolution->paths, $scopeResolution->fileDiscovery);

        if ($result->filesAnalyzed === 0 && $scopeResolution->analyzeScope === null) {
            $output->writeln('<comment>No PHP files found in the given paths.</comment>');

            return self::SUCCESS;
        }

        // Feed collected suppressions into the filter pipeline before filtering
        $this->violationFilterPipeline->loadSuppressions($result->suppressions);

        $filterResult = $this->filterViolations($result, $input, $output, $scopeResolution);
        $filteredViolations = $filterResult->violations;

        $this->resultPresenter->generateBaselineIfRequested($result->violations, $input, $output);

        $partialAnalysis = $scopeResolution->analyzeScope !== null;
        $exitCode = $this->resultPresenter->presentResults($filteredViolations, $result, $input, $output, $partialAnalysis);

        $this->resultPresenter->presentProfile($input, $output);

        return $exitCode;
    }

    /**
     * Resolves configuration using the pipeline.
     */
    private function resolveConfiguration(InputInterface $input): ResolvedConfiguration
    {
        $configPath = $input->getOption('config');
        $context = new ConfigurationContext(
            $input,
            getcwd() ?: '.',
            \is_string($configPath) && $configPath !== '' ? $configPath : null,
        );

        return $this->configurationPipeline->resolve($context);
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
     * Runs the analysis on specified paths.
     *
     * @param list<string> $paths
     */
    private function runAnalysis(array $paths, \AiMessDetector\Analysis\Discovery\FileDiscoveryInterface $fileDiscovery): \AiMessDetector\Analysis\Pipeline\AnalysisResult
    {
        return $this->analyzer->analyze($paths, $fileDiscovery);
    }

    /**
     * Filters violations through the pipeline and handles output for stale baseline / show-resolved / etc.
     */
    private function filterViolations(
        \AiMessDetector\Analysis\Pipeline\AnalysisResult $result,
        InputInterface $input,
        OutputInterface $output,
        GitScopeResolution $scopeResolution,
    ): ViolationFilterResult {
        $baselinePath = $input->getOption('baseline');
        /** @var list<string> $cliExcludePaths */
        $cliExcludePaths = $input->getOption('exclude-path');

        $gitScope = null;
        if ($scopeResolution->gitClient !== null && $scopeResolution->reportScope !== null) {
            $gitScope = new GitScopeFilterConfig(
                gitClient: $scopeResolution->gitClient,
                reportScope: $scopeResolution->reportScope,
                analyzeScope: $scopeResolution->analyzeScope,
                strictMode: (bool) $input->getOption('report-strict'),
            );
        }

        $options = new ViolationFilterOptions(
            baselinePath: \is_string($baselinePath) && $baselinePath !== '' ? $baselinePath : null,
            ignoreStaleBaseline: (bool) $input->getOption('baseline-ignore-stale'),
            disableSuppression: (bool) $input->getOption('no-suppression'),
            excludePaths: $cliExcludePaths,
            gitScope: $gitScope,
        );

        $filterResult = $this->violationFilterPipeline->filter($result->violations, $options);

        // Handle stale baseline entries
        if ($filterResult->staleBaselineKeys !== []) {
            $this->handleStaleBaselineOutput($filterResult, $options, $output);
        }

        // Show resolved violations if requested
        if ($input->getOption('show-resolved') && $filterResult->baselineFilter !== null) {
            $resolved = $filterResult->baselineFilter->getResolvedFromBaseline($result->violations);
            $resolvedCount = array_sum(array_map(count(...), $resolved));

            if ($resolvedCount > 0) {
                $output->writeln(\sprintf(
                    '<info>%d violations from baseline have been resolved!</info>',
                    $resolvedCount,
                ));
            }
        }

        // Show suppressed count if requested
        if ($input->getOption('show-suppressed') && $filterResult->suppressionFiltered > 0) {
            $output->writeln(\sprintf(
                '<info>%d violations were suppressed by @aimd-ignore tags</info>',
                $filterResult->suppressionFiltered,
            ));
        }

        // Show path exclusion info in verbose mode
        if ($filterResult->pathExclusionFiltered > 0 && $output->isVerbose()) {
            $output->writeln(\sprintf(
                '<info>%d violation(s) suppressed by path exclusion patterns</info>',
                $filterResult->pathExclusionFiltered,
            ));
        }

        // Show warning about partial analysis if analyze scope was used
        if (
            $gitScope !== null
            && $gitScope->analyzeScope !== null
            && $filterResult->violations !== []
        ) {
            $output->writeln(
                '<comment>Note: Aggregated metrics not available in partial analysis mode.</comment>',
            );
        }

        return $filterResult;
    }

    /**
     * Handles output for stale baseline entries and throws if not ignored.
     */
    private function handleStaleBaselineOutput(
        ViolationFilterResult $filterResult,
        ViolationFilterOptions $options,
        OutputInterface $output,
    ): void {
        if ($options->ignoreStaleBaseline) {
            $output->writeln(\sprintf(
                '<comment>Warning: Baseline contains %d stale entries (symbols no longer exist)</comment>',
                $filterResult->staleBaselineCount,
            ));
            $output->writeln(\sprintf(
                '<comment>Run `bin/aimd baseline:cleanup %s` to remove them.</comment>',
                $options->baselinePath ?? '',
            ));

            return;
        }

        $output->writeln(\sprintf(
            '<error>Error: Baseline contains %d stale entries (symbols no longer exist):</error>',
            $filterResult->staleBaselineCount,
        ));
        foreach ($filterResult->staleBaselineKeys as $key) {
            $output->writeln(\sprintf('  - %s', $key));
        }
        $output->writeln('');
        $output->writeln(\sprintf('Run `bin/aimd baseline:cleanup %s` to remove stale entries.', $options->baselinePath ?? ''));
        $output->writeln('Or use --baseline-ignore-stale to continue anyway.');

        throw new InvalidArgumentException('Baseline contains stale entries');
    }

    /**
     * Validates that all provided paths exist.
     *
     * @param list<string> $paths
     *
     * @return list<string> Error messages (empty if all valid)
     */
    private function validatePaths(array $paths): array
    {
        $errors = [];
        foreach ($paths as $path) {
            if (!file_exists($path)) {
                $errors[] = "Error: path '{$path}' does not exist";
            }
        }

        return $errors;
    }

    /**
     * Validates that --workers value is a non-negative integer.
     */
    private function validateWorkersOption(InputInterface $input): void
    {
        $workers = $input->getOption('workers');
        if ($workers === null) {
            return;
        }

        $filtered = filter_var($workers, \FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        if ($filtered === false) {
            throw new InvalidArgumentException(
                \sprintf('Invalid value "%s" for --workers. Expected a non-negative integer.', $workers),
            );
        }
    }

    /**
     * Warns if both --disable-rule and --only-rule are used simultaneously.
     */
    private function warnAboutConflictingRuleFilters(ResolvedConfiguration $resolved, OutputInterface $output): void
    {
        if ($resolved->analysis->disabledRules !== [] && $resolved->analysis->onlyRules !== []) {
            $output->writeln(
                '<comment>Warning: both --disable-rule and --only-rule are active. This may result in no rules being enabled.</comment>',
            );
        }
    }

    /**
     * Logs which configuration sources were applied (verbose mode only).
     */
    private function logConfigSources(ResolvedConfiguration $resolved, OutputInterface $output): void
    {
        if (!$output->isVerbose() || $resolved->appliedSources === []) {
            return;
        }

        $output->writeln(\sprintf(
            '<info>Configuration loaded from: %s</info>',
            implode(', ', $resolved->appliedSources),
        ));
    }

    /**
     * Warns about unknown rule names in --only-rule and --disable-rule.
     */
    private function warnAboutUnknownRules(ResolvedConfiguration $resolved, OutputInterface $output): void
    {
        $knownNames = array_map(
            fn(string $class): string => $class::NAME,
            $this->ruleRegistry->getClasses(),
        );

        $checkNames = [
            ...$resolved->analysis->onlyRules,
            ...$resolved->analysis->disabledRules,
        ];

        foreach ($checkNames as $name) {
            $matched = false;
            foreach ($knownNames as $known) {
                // Support exact match, prefix match (e.g., "complexity" matches "complexity.cyclomatic"),
                // and reverse prefix match (e.g., "complexity.cyclomatic.method" refines "complexity.cyclomatic")
                if ($name === $known || str_starts_with($known, $name . '.') || str_starts_with($name, $known . '.')) {
                    $matched = true;
                    break;
                }
            }
            if (!$matched) {
                $output->writeln(\sprintf(
                    '<comment>Warning: rule "%s" does not match any registered rule</comment>',
                    $name,
                ));
            }
        }
    }
}
