<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console\Command;

use InvalidArgumentException;
use Qualimetrix\Analysis\Configuration\Contract\Exception\ConfigLoadException;
use Qualimetrix\Analysis\Configuration\RetiredSuppressionOptions;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\ComputedMetricConfigurationException;
use Qualimetrix\Analysis\Policy\Architecture\Contract\ArchitectureConfigurationException;
use Qualimetrix\Analysis\Policy\Architecture\Contract\ArchitecturePreparationException;
use Qualimetrix\Analysis\Policy\Baseline\BaselineLoadException;
use Qualimetrix\Analysis\Run\Contract\Configuration\RunConfiguration;
use Qualimetrix\Analysis\Run\Contract\Configuration\RunConfigurationResolverInterface;
use Qualimetrix\Analysis\Run\Contract\Pipeline\AnalysisPipelineInterface;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Infrastructure\Cache\Contract\CacheConfigurationResolverInterface;
use Qualimetrix\Infrastructure\Console\CheckScopeResolver;
use Qualimetrix\Infrastructure\Console\ConfigurationInputAdapter;
use Qualimetrix\Infrastructure\Console\FilteredInputDefinition;
use Qualimetrix\Infrastructure\Console\FindingFilterOrchestrator;
use Qualimetrix\Infrastructure\Console\ResultPresenter;
use Qualimetrix\Infrastructure\Console\RuleInputValidator;
use Qualimetrix\Infrastructure\Console\RuntimeConfigurator;
use Qualimetrix\Infrastructure\Parallel\Contract\ParallelConfigurationResolverInterface;
use Qualimetrix\Infrastructure\Rule\Exception\ConflictingCliAliasException;
use Qualimetrix\Reporting\Contract\OutputFormatResolverInterface;
use Qualimetrix\Reporting\FindingProjection\Contract\ConfiguredFindingExclusionsResolverInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Exception\ExceptionInterface as ConsoleExceptionInterface;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputInterface;
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

    public function __construct(
        private readonly AnalysisPipelineInterface $analyzer,
        private readonly FindingFilterOrchestrator $findingFilterOrchestrator,
        private readonly RuntimeConfigurator $runtimeConfigurator,
        private readonly ResultPresenter $resultPresenter,
        private readonly RuleInputValidator $ruleInputValidator,
        private readonly CheckScopeResolver $checkScopeResolver,
        private readonly ConfigurationInputAdapter $configurationInputAdapter,
        private readonly RunConfigurationResolverInterface $runConfigurationResolver,
        private readonly CacheConfigurationResolverInterface $cacheConfigurationResolver,
        private readonly ParallelConfigurationResolverInterface $parallelConfigurationResolver,
        private readonly ConfiguredFindingExclusionsResolverInterface $findingExclusionsResolver,
        private readonly OutputFormatResolverInterface $outputFormatResolver,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->hiddenOptionNames = $this->ruleInputValidator->configureCheckCommand($this);
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

        $filteredDefinition = new FilteredInputDefinition();
        $filteredDefinition->setArguments($definition->getArguments());
        $filteredDefinition->setOptions($definition->getOptions());
        $filteredDefinition->setHiddenOptionNames($this->hiddenOptionNames);

        return $filteredDefinition;
    }

    /**
     * Exit code for input/configuration errors (distinct from analysis results).
     *
     * Exit code semantics:
     * - 0: clean (no findings at configured fail level)
     * - 1: warnings found (with --fail-on=warning)
     * - 2: errors found (findings at error severity)
     * - 3: input/configuration error (bad paths, invalid config, etc.)
     */
    private const int EXIT_CONFIG_ERROR = 3;

    /** @var array<string, string> retired flag => the flag that suppresses findings now */
    private const array RETIRED_SUPPRESSION_FLAGS = [
        '--exclude-path' => '--suppress-path',
        '--exclude-namespace' => '--suppress-namespace',
    ];

    /**
     * Refuses a retired suppression flag by name, with the text and the exit
     * code its config-file twins already use.
     *
     * The flag is not declared in
     * {@see \Qualimetrix\Infrastructure\Console\CheckCommandDefinition}, which would put it
     * back in `--help` as if it still worked; it is recognized here instead,
     * from the binding failure Symfony already raises for it. Reading the token
     * list ourselves would mean not knowing which token is an option name and
     * which is another option's value: a path literally called
     * `--exclude-path` was refused as a flag, and whether it was refused
     * depended on which of the two equivalent spellings the user wrote it in.
     * The parser knows the difference and names the offending option in its
     * message; anything it did bind is not a retired flag.
     *
     * Refusing on this command and not globally is what keeps
     * `graph:export --exclude-namespace` alive — that flag was never renamed,
     * it drops files from the graph rather than suppressing findings.
     *
     * Symfony's own "option does not exist" names neither the replacement nor
     * the fork, and leaves exit 1, which a CI wrapper reads as "the run fell
     * over" rather than "there is a migration to make".
     */
    public function run(InputInterface $input, OutputInterface $output): int
    {
        try {
            return parent::run($input, $output);
        } catch (ConsoleExceptionInterface $e) {
            foreach (self::RETIRED_SUPPRESSION_FLAGS as $retired => $current) {
                if (!str_contains($e->getMessage(), \sprintf('"%s"', $retired))) {
                    continue;
                }

                $this->resultPresenter->writeDiagnostic(
                    $output,
                    '<error>' . RetiredSuppressionOptions::refusalText($retired, $current) . '</error>',
                );

                return self::EXIT_CONFIG_ERROR;
            }

            throw $e;
        }
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            return $this->doExecute($input, $output);
        } catch (ConflictingCliAliasException $e) {
            $this->resultPresenter->writeDiagnostic($output, \sprintf(
                '<error>CLI alias conflict: "%s" is used by both "%s" and "%s" rules</error>',
                $e->alias,
                $e->firstRule,
                $e->secondRule,
            ));

            return self::EXIT_CONFIG_ERROR;
        } catch (ConfigLoadException|ArchitectureConfigurationException $e) {
            $this->resultPresenter->writeDiagnostic($output, \sprintf(
                '<error>Configuration error: %s</error>',
                $e->getMessage(),
            ));

            return self::EXIT_CONFIG_ERROR;
        } catch (ArchitecturePreparationException $e) {
            // Template-layer expansion failures are user-fixable misconfiguration
            // (typo'd templates, ceiling exceeded, name collisions). Surface them
            // with the same framing and exit code as ConfigLoadException so the
            // user sees them as configuration errors, not internal crashes.
            $this->resultPresenter->writeDiagnostic($output, \sprintf(
                '<error>Architecture configuration error: %s</error>',
                $e->getMessage(),
            ));

            return self::EXIT_CONFIG_ERROR;
        } catch (InvalidArgumentException $e) {
            $this->resultPresenter->writeDiagnostic($output, \sprintf('<error>%s</error>', $e->getMessage()));

            return self::EXIT_CONFIG_ERROR;
        } catch (ComputedMetricConfigurationException|BaselineLoadException $e) {
            // User-supplied formulas and baseline envelopes are input/configuration
            // errors: a bad formula or an unreadable baseline is theirs to fix,
            // not an internal crash.
            $this->resultPresenter->writeDiagnostic($output, \sprintf('<error>Configuration error: %s</error>', $e->getMessage()));

            return self::EXIT_CONFIG_ERROR;
        } catch (Throwable $e) {
            $this->resultPresenter->writeDiagnostic($output, \sprintf(
                '<error>Unexpected error: %s</error>',
                $e->getMessage(),
            ));

            if ($output->isVerbose()) {
                $this->resultPresenter->writeDiagnostic($output, '');
                $this->resultPresenter->writeDiagnostic($output, '<comment>Stack trace:</comment>');
                $this->resultPresenter->writeDiagnostic($output, $e->getTraceAsString());
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
        $this->runtimeConfigurator->resetRunState();

        // Resolve configuration through pipeline
        $document = $this->configurationInputAdapter->resolve($input);
        $runConfiguration = $this->runConfigurationResolver->resolve($document);
        $cacheConfiguration = $this->cacheConfigurationResolver->resolve($document, $runConfiguration->projectRoot);
        $parallelConfiguration = $this->parallelConfigurationResolver->resolve($document);
        $findingConfiguration = $this->ruleInputValidator->resolve($document, $input);
        $findingExclusions = $this->findingExclusionsResolver->resolve($document);
        $outputFormat = $this->outputFormatResolver->resolve($document);
        $exitPolicy = $this->configurationInputAdapter->exitPolicy($document);

        // Configure runtime using resolved config
        $this->runtimeConfigurator->configure(
            $document,
            $findingConfiguration,
            $cacheConfiguration,
            $parallelConfiguration,
            $input,
            $output,
        );

        if ($this->runtimeConfigurator->clearCacheIfRequested($input)) {
            $this->resultPresenter->writeDiagnostic($output, '<info>Cache cleared.</info>');
        }

        $selectionWarning = $this->ruleInputValidator->conflictingSelectionWarning($findingConfiguration);
        if ($selectionWarning !== null) {
            $this->writeWarning($output, $selectionWarning);
        }
        if ($output->isVerbose() && $document->appliedSources() !== []) {
            $this->resultPresenter->writeDiagnostic($output, \sprintf(
                '<info>Configuration loaded from: %s</info>',
                implode(', ', $document->appliedSources()),
            ));
        }

        $resolvedScope = $this->checkScopeResolver->resolve($input, $runConfiguration);
        $scopeResolution = $resolvedScope->scope;

        $pathErrors = $this->validatePaths($scopeResolution->paths);
        if ($pathErrors !== []) {
            foreach ($pathErrors as $error) {
                $this->resultPresenter->writeDiagnostic($output, \sprintf('<error>%s</error>', $error));
            }

            return self::EXIT_CONFIG_ERROR;
        }

        $projectRoot = $runConfiguration->projectRoot;
        $this->warnIfComposerJsonMissing($projectRoot, $output);
        foreach ($resolvedScope->warnings as $warning) {
            $this->writeWarning($output, \sprintf('Warning: %s', $warning));
        }

        $scopedRunConfiguration = new RunConfiguration(
            $scopeResolution->paths,
            $runConfiguration->pathExcludes,
            $runConfiguration->projectRoot,
            $runConfiguration->generatedFilePolicy,
        );
        $result = $this->runAnalysis($scopedRunConfiguration, $scopeResolution->fileDiscovery);

        $projectionOptions = $this->findingFilterOrchestrator->projectionOptions(
            $findingExclusions,
            $input,
            $scopeResolution,
        );
        $filterResult = $this->findingFilterOrchestrator->filterAndReport(
            $result,
            $input,
            $output,
            $scopeResolution,
            $projectionOptions,
        );
        $filteredFindings = $filterResult->findings;

        // `check` no longer writes baselines — `bin/qmx baseline:generate` does —
        // so ResultPresenter no longer has a "baseline was just captured, report
        // success regardless" path to opt into here.
        $exitCode = $this->resultPresenter->presentResults(
            $filteredFindings,
            $result,
            $input,
            $output,
            $projectRoot,
            outputFormat: $outputFormat,
            exitPolicy: $exitPolicy,
            reportScope: $scopeResolution->reportScope,
            filterResult: $filterResult,
            projectionOptions: $projectionOptions,
        );

        $this->resultPresenter->presentProfile($input, $output);

        return $exitCode;
    }

    /**
     * Runs the analysis on specified paths.
     */
    private function runAnalysis(RunConfiguration $configuration, \Qualimetrix\Analysis\Run\Contract\Discovery\FileDiscoveryInterface $fileDiscovery): \Qualimetrix\Analysis\Run\Contract\Pipeline\AnalysisResult
    {
        return $this->analyzer->analyze($configuration, $fileDiscovery);
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
     * Writes a warning through the run's single error-stream owner, so it
     * cannot land inside a progress frame that is about to erase itself.
     */
    private function writeWarning(OutputInterface $output, string $message): void
    {
        $this->resultPresenter->writeDiagnostic($output, \sprintf('<comment>%s</comment>', $message));
    }

}
