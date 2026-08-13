<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console\Command;

use InvalidArgumentException;
use Qualimetrix\Analysis\Configuration\Contract\Exception\ConfigLoadException;
use Qualimetrix\Analysis\Configuration\Contract\Pipeline\ConfigurationContext;
use Qualimetrix\Analysis\Configuration\Contract\Pipeline\ConfigurationPipelineInterface;
use Qualimetrix\Analysis\Configuration\Contract\TransitionalResolvedConfiguration;
use Qualimetrix\Analysis\Run\Contract\Pipeline\AnalysisPipelineInterface;
use Qualimetrix\Architecture\Processing\LayerExpansionException;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Infrastructure\Cache\CacheFactory;
use Qualimetrix\Infrastructure\Console\CheckCommandDefinition;
use Qualimetrix\Infrastructure\Console\CheckScopeResolver;
use Qualimetrix\Infrastructure\Console\DiagnosticOutput;
use Qualimetrix\Infrastructure\Console\FilteredInputDefinition;
use Qualimetrix\Infrastructure\Console\ResultPresenter;
use Qualimetrix\Infrastructure\Console\RuleInputValidator;
use Qualimetrix\Infrastructure\Console\RuntimeConfigurator;
use Qualimetrix\Infrastructure\Console\ViolationFilterOrchestrator;
use Qualimetrix\Infrastructure\Rule\Exception\ConflictingCliAliasException;
use Qualimetrix\Infrastructure\Rule\RuleRegistryInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

#[AsCommand(
    name: 'check',
    description: 'Check PHP code for complexity and structural issues',
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
        private readonly ConfigurationPipelineInterface $configurationPipeline,
        private readonly RuntimeConfigurator $runtimeConfigurator,
        private readonly ResultPresenter $resultPresenter,
        private readonly RuleInputValidator $ruleInputValidator,
        private readonly DiagnosticOutput $diagnosticOutput,
        private readonly CheckScopeResolver $checkScopeResolver,
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

    /**
     * Exit code for input/configuration errors (distinct from analysis results).
     *
     * Exit code semantics:
     * - 0: clean (no violations at configured fail level)
     * - 1: warnings found (with --fail-on=warning)
     * - 2: errors found (violations at error severity)
     * - 3: input/configuration error (bad paths, invalid config, etc.)
     */
    private const int EXIT_CONFIG_ERROR = 3;

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            return $this->doExecute($input, $output);
        } catch (ConflictingCliAliasException $e) {
            $this->diagnosticOutput->write($output, \sprintf(
                '<error>CLI alias conflict: "%s" is used by both "%s" and "%s" rules</error>',
                $e->alias,
                $e->firstRule,
                $e->secondRule,
            ));

            return self::EXIT_CONFIG_ERROR;
        } catch (ConfigLoadException $e) {
            $this->diagnosticOutput->write($output, \sprintf(
                '<error>Configuration error: %s</error>',
                $e->getMessage(),
            ));

            return self::EXIT_CONFIG_ERROR;
        } catch (LayerExpansionException $e) {
            // Template-layer expansion failures are user-fixable misconfiguration
            // (typo'd templates, ceiling exceeded, name collisions). Surface them
            // with the same framing and exit code as ConfigLoadException so the
            // user sees them as configuration errors, not internal crashes.
            $this->diagnosticOutput->write($output, \sprintf(
                '<error>Architecture configuration error: %s</error>',
                $e->getMessage(),
            ));

            return self::EXIT_CONFIG_ERROR;
        } catch (InvalidArgumentException $e) {
            $this->diagnosticOutput->write($output, \sprintf('<error>%s</error>', $e->getMessage()));

            return self::EXIT_CONFIG_ERROR;
        } catch (Throwable $e) {
            $this->diagnosticOutput->write($output, \sprintf(
                '<error>Unexpected error: %s</error>',
                $e->getMessage(),
            ));

            if ($output->isVerbose()) {
                $this->diagnosticOutput->write($output, '');
                $this->diagnosticOutput->write($output, '<comment>Stack trace:</comment>');
                $this->diagnosticOutput->write($output, $e->getTraceAsString());
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
        $this->runtimeConfigurator->configure($resolved, $input, $output);

        $this->ruleInputValidator->validate($resolved, $input);

        $this->clearCacheIfRequested($input, $output);

        $this->validateWorkersOption($input);
        $this->warnAboutConflictingRuleFilters($resolved, $output);
        $this->logConfigSources($resolved, $output);

        $resolvedScope = $this->checkScopeResolver->resolve($input, $resolved);
        $scopeResolution = $resolvedScope->scope;

        $pathErrors = $this->validatePaths($scopeResolution->paths);
        if ($pathErrors !== []) {
            foreach ($pathErrors as $error) {
                $this->diagnosticOutput->write($output, \sprintf('<error>%s</error>', $error));
            }

            return self::EXIT_CONFIG_ERROR;
        }

        $projectRoot = $resolved->runtime->projectRoot;
        $this->warnIfComposerJsonMissing($projectRoot, $output);
        foreach ($resolvedScope->warnings as $warning) {
            $this->writeWarning($output, \sprintf('Warning: %s', $warning));
        }

        $result = $this->runAnalysis($scopeResolution->paths, $scopeResolution->fileDiscovery);

        $filterResult = $this->violationFilterOrchestrator->filterAndReport($result, $input, $output, $scopeResolution);
        $filteredViolations = $filterResult->violations;

        $scopedReporting = $scopeResolution->reportScope !== null;

        // `check` no longer writes baselines — `bin/qmx baseline:generate` does —
        // so ResultPresenter no longer has a "baseline was just captured, report
        // success regardless" path to opt into here.
        $exitCode = $this->resultPresenter->presentResults(
            $filteredViolations,
            $result,
            $input,
            $output,
            $scopedReporting,
        );

        $this->resultPresenter->presentProfile($input, $output);

        return $exitCode;
    }

    /**
     * Resolves configuration using the pipeline.
     *
     * Working directory is captured from getcwd() which is the project root
     * (already changed by Application::doRun() if --working-dir was passed).
     */
    private function resolveConfiguration(InputInterface $input): TransitionalResolvedConfiguration
    {
        $configPath = $input->getOption('config');
        $cwd = getcwd();
        $workingDirectory = $cwd !== false ? $cwd : '.';

        $context = new ConfigurationContext(
            $input,
            $workingDirectory,
            \is_string($configPath) && $configPath !== '' ? $configPath : null,
        );

        return $this->configurationPipeline->resolve($context);
    }

    /**
     * Clears cache if requested via CLI option.
     */
    private function clearCacheIfRequested(InputInterface $input, OutputInterface $output): void
    {
        if ($input->getOption('clear-cache') === true) {
            $cache = $this->cacheFactory->create();
            $cache->clear();
            $this->diagnosticOutput->write($output, '<info>Cache cleared.</info>');
        }
    }

    /**
     * Runs the analysis on specified paths.
     *
     * @param list<AbsolutePath> $paths
     */
    private function runAnalysis(array $paths, \Qualimetrix\Analysis\Run\Contract\Discovery\FileDiscoveryInterface $fileDiscovery): \Qualimetrix\Analysis\Run\Contract\Pipeline\AnalysisResult
    {
        return $this->analyzer->analyze($paths, $fileDiscovery);
    }

    /**
     * Validates that all provided paths exist.
     *
     * @param list<AbsolutePath> $paths
     *
     * @return list<string> Error messages (empty if all valid)
     */
    private function validatePaths(array $paths): array
    {
        $errors = [];
        foreach ($paths as $path) {
            if (!$path->exists()) {
                $errors[] = \sprintf("Error: path '%s' does not exist", $path->value());
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
    private function warnAboutConflictingRuleFilters(TransitionalResolvedConfiguration $resolved, OutputInterface $output): void
    {
        if ($resolved->runtime->disabledRules !== [] && $resolved->runtime->onlyRules !== []) {
            $this->writeWarning(
                $output,
                'Warning: both --disable-rule and --only-rule are active. This may result in no rules being enabled.',
            );
        }
    }

    /**
     * Warns when composer.json is not found in project root.
     */
    private function warnIfComposerJsonMissing(AbsolutePath $projectRoot, OutputInterface $output): void
    {
        if (!file_exists($projectRoot->value() . '/composer.json')) {
            $this->writeWarning(
                $output,
                \sprintf('Warning: No composer.json found in %s. Namespace detection and coupling metrics may be inaccurate.', $projectRoot->value()),
            );
        }
    }

    /**
     * Writes a warning to stderr to avoid polluting structured output.
     */
    private function writeWarning(OutputInterface $output, string $message): void
    {
        if ($output instanceof ConsoleOutputInterface) {
            $output->getErrorOutput()->writeln(\sprintf('<comment>%s</comment>', $message));
        }
    }

    /**
     * Logs which configuration sources were applied (verbose mode only).
     */
    private function logConfigSources(TransitionalResolvedConfiguration $resolved, OutputInterface $output): void
    {
        if (!$output->isVerbose() || $resolved->appliedSources === []) {
            return;
        }

        $this->diagnosticOutput->write($output, \sprintf(
            '<info>Configuration loaded from: %s</info>',
            implode(', ', $resolved->appliedSources),
        ));
    }

}
