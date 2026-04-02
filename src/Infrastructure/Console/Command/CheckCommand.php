<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console\Command;

use InvalidArgumentException;
use Qualimetrix\Analysis\Pipeline\AnalysisPipelineInterface;
use Qualimetrix\Configuration\Exception\ConfigLoadException;
use Qualimetrix\Configuration\Pipeline\ConfigurationContext;
use Qualimetrix\Configuration\Pipeline\ConfigurationPipeline;
use Qualimetrix\Configuration\Pipeline\ResolvedConfiguration;
use Qualimetrix\Infrastructure\Cache\CacheFactory;
use Qualimetrix\Infrastructure\Console\BaselinePresenter;
use Qualimetrix\Infrastructure\Console\CheckCommandDefinition;
use Qualimetrix\Infrastructure\Console\FilteredInputDefinition;
use Qualimetrix\Infrastructure\Console\ResultPresenter;
use Qualimetrix\Infrastructure\Console\RuntimeConfigurator;
use Qualimetrix\Infrastructure\Console\ScopeWarningChecker;
use Qualimetrix\Infrastructure\Console\ViolationFilterOrchestrator;
use Qualimetrix\Infrastructure\Git\GitScopeResolver;
use Qualimetrix\Infrastructure\Rule\Exception\ConflictingCliAliasException;
use Qualimetrix\Infrastructure\Rule\RuleRegistryInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputDefinition;
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
    /** @var list<string> Rule-specific option names hidden from --help */
    private array $hiddenOptionNames = [];

    private ?FilteredInputDefinition $filteredDefinition = null;
    private ?int $filteredDefinitionSource = null;

    public function __construct(
        private readonly RuleRegistryInterface $ruleRegistry,
        private readonly AnalysisPipelineInterface $analyzer,
        private readonly CacheFactory $cacheFactory,
        private readonly ViolationFilterOrchestrator $violationFilterOrchestrator,
        private readonly ConfigurationPipeline $configurationPipeline,
        private readonly RuntimeConfigurator $runtimeConfigurator,
        private readonly ResultPresenter $resultPresenter,
        private readonly BaselinePresenter $baselinePresenter,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->hiddenOptionNames = CheckCommandDefinition::addOptions($this, $this->ruleRegistry);
        $this->setHelp(
            'Run <info>bin/qmx rules</info> to see all available rules and their options.' . "\n"
            . 'Use <info>--rule-opt=rule-name:option=value</info> to set rule-specific thresholds.',
        );
    }

    /**
     * Returns a FilteredInputDefinition that hides rule-specific options
     * from --help output while keeping them functional for input parsing.
     *
     * The Symfony TextDescriptor iterates getDefinition()->getOptions() to render help.
     * FilteredInputDefinition overrides getOptions() to exclude hidden options,
     * while hasOption()/getOption()/getOptionForShortcut() still resolve them normally.
     */
    public function getDefinition(): InputDefinition
    {
        $definition = parent::getDefinition();

        if ($this->hiddenOptionNames === []) {
            return $definition;
        }

        // Rebuild when parent definition changes (e.g., after mergeApplicationDefinition)
        if ($this->filteredDefinition === null || $this->filteredDefinitionSource !== spl_object_id($definition)) {
            $this->filteredDefinition = new FilteredInputDefinition();
            $this->filteredDefinition->setArguments($definition->getArguments());
            $this->filteredDefinition->setOptions($definition->getOptions());
            $this->filteredDefinition->setHiddenOptionNames($this->hiddenOptionNames);
            $this->filteredDefinitionSource = spl_object_id($definition);
        }

        return $this->filteredDefinition;
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

        $this->warnAboutPartialScope($scopeResolution->paths, $input, $output);

        $result = $this->runAnalysis($scopeResolution->paths, $scopeResolution->fileDiscovery);

        if ($result->filesAnalyzed === 0 && $scopeResolution->analyzeScope === null) {
            $output->writeln('<comment>No PHP files found in the given paths.</comment>');

            return self::SUCCESS;
        }

        $filterResult = $this->violationFilterOrchestrator->filterAndReport($result, $input, $output, $scopeResolution);
        $filteredViolations = $filterResult->violations;

        $baselineGenerated = $this->baselinePresenter->generateBaselineIfRequested($result->violations, $input, $output);

        $scopedReporting = $scopeResolution->analyzeScope !== null;
        $exitCode = $this->resultPresenter->presentResults(
            $filteredViolations,
            $result,
            $input,
            $output,
            $baselineGenerated,
            $scopedReporting,
            $scopeResolution->scopeFilePaths,
        );

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
    private function runAnalysis(array $paths, \Qualimetrix\Analysis\Discovery\FileDiscoveryInterface $fileDiscovery): \Qualimetrix\Analysis\Pipeline\AnalysisResult
    {
        return $this->analyzer->analyze($paths, $fileDiscovery);
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
     * Warns when analyzed paths don't cover the full project scope.
     *
     * @param list<string> $paths
     */
    private function warnAboutPartialScope(array $paths, InputInterface $input, OutputInterface $output): void
    {
        // Only show for human-readable formats; structured formats would be corrupted
        $format = $input->getOption('format');
        $textFormats = [null, 'text', 'summary', 'health', 'metrics', 'github'];
        if (!\in_array($format, $textFormats, true)) {
            return;
        }

        $checker = new ScopeWarningChecker();
        $warnings = $checker->check(getcwd() ?: '.', $paths);
        foreach ($warnings as $warning) {
            $output->writeln(\sprintf('<comment>Warning: %s</comment>', $warning));
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
