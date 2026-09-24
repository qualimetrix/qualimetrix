<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console\Command;

use InvalidArgumentException;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Analysis\Configuration\RetiredSuppressionOptions;
use Qualimetrix\Analysis\Policy\Baseline\BaselineLoader;
use Qualimetrix\Analysis\Run\Contract\Configuration\RunConfiguration;
use Qualimetrix\Analysis\Run\Contract\Pipeline\AnalysisPipelineInterface;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Core\ProductIdentity;
use Qualimetrix\Infrastructure\Console\CheckConfigurationResolvers;
use Qualimetrix\Infrastructure\Console\CheckScopeResolver;
use Qualimetrix\Infrastructure\Console\ConfigurationInputAdapter;
use Qualimetrix\Infrastructure\Console\FilteredInputDefinition;
use Qualimetrix\Infrastructure\Console\FindingFilterOrchestrator;
use Qualimetrix\Infrastructure\Console\Refusal\RefusalPresenter;
use Qualimetrix\Infrastructure\Console\ResultPresenter;
use Qualimetrix\Infrastructure\Console\RuleInputValidator;
use Qualimetrix\Infrastructure\Console\RuntimeConfigurator;
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
        private readonly CheckConfigurationResolvers $configurationResolvers,
        private readonly RefusalPresenter $refusalPresenter,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->hiddenOptionNames = $this->ruleInputValidator->configureCheckCommand($this);
        $this->setHelp(
            'Run <info>bin/qmx rules</info> to see all available rules and their options.' . "\n"
            . 'Use <info>--rule-opt=rule-name:option=value</info> to set rule-specific thresholds.' . "\n\n"
            . \sprintf('Docs: %s', ProductIdentity::llmsTxtUrl()),
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

                return $this->refusalPresenter->refusal(
                    $output,
                    self::formatOption($input),
                    ConfigurationRefusal::aboutCommandLineInput(
                        $retired,
                        RetiredSuppressionOptions::refusalText($retired, $current),
                    ),
                );
            }

            throw $e;
        }
    }

    /**
     * `--format`, read off the raw argv rather than the bound
     * {@see InputInterface}. Symfony's own option-binding failure is exactly
     * what this method exists to survive: {@see self::run()} catches it
     * before `execute()` ever runs, so `getOption()` may not have a value
     * yet. `getParameterOption()` reads the tokens directly and needs no
     * binding, which is also why {@see self::execute()} uses this instead of
     * `getOption('format')` — a `--format` value is available for the
     * envelope even when everything after it in `doExecute()` throws before
     * resolving one itself.
     */
    private static function formatOption(InputInterface $input): ?string
    {
        $value = $input->getParameterOption(['--format', '-f'], null);

        return \is_string($value) ? $value : null;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $format = self::formatOption($input);

        try {
            return $this->doExecute($input, $output);
        } catch (ConfigurationRefusal $refusal) {
            // First clause: the carrier is a RuntimeException, and every
            // clause below it — down to `catch (Throwable)` — would otherwise
            // swallow it as a plain exception.
            return $this->refusalPresenter->refusal($output, $format, $refusal);
        } catch (InvalidArgumentException $e) {
            // The named secondary signal for code 3:
            // an `InvalidArgumentException` that never became a carrier.
            // Printed verbatim, unlike the clauses above — its message is
            // already the whole sentence.
            return $this->refusalPresenter->fallbackRefusal($output, $format, $e);
        } catch (Throwable $e) {
            return $this->refusalPresenter->internalError($output, $format, $e);
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

        // Resolve configuration through pipeline. It refuses an option written
        // empty, so it runs before anything below reads `--output` as a path.
        $document = $this->configurationInputAdapter->resolve($input);

        // Refuse an unwritable `--output` before analysis starts. This fast
        // precheck is not a guarantee because writability can change later.
        $this->resultPresenter->assertOutputIsWritable($input);
        $namespacePattern = $this->resultPresenter->bindOutputOptions($input);
        $resolved = $this->configurationResolvers->resolve($document);
        $runConfiguration = $resolved->runConfiguration;
        $cacheConfiguration = $resolved->cacheConfiguration;
        $parallelConfiguration = $resolved->parallelConfiguration;
        $findingConfiguration = $this->ruleInputValidator->resolve($document, $input);
        $findingExclusions = $resolved->findingExclusions;
        $outputFormat = $resolved->outputFormat;
        $this->resultPresenter->bindOutputFormat($input, $outputFormat);
        $exitPolicy = $this->configurationInputAdapter->exitPolicy($document);

        // Configure runtime using resolved config
        $this->runtimeConfigurator->configure(
            $document,
            $runConfiguration,
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
            throw ConfigurationRefusal::aboutCommandLineInput(
                'paths',
                implode(' ', $pathErrors),
            );
        }

        $projectRoot = $runConfiguration->projectRoot;
        $this->warnIfComposerJsonMissing($projectRoot, $output);
        foreach ($resolvedScope->warnings as $warning) {
            $this->writeWarning($output, \sprintf('Warning: %s', $warning));
        }

        // Decodes `--suppress-path`, `--suppress-namespace` and `--baseline`
        // as written — a KIND:VALUE selector parse and a command-line-spelling
        // type check, neither of which reads an analysis result — so it runs
        // before the analysis those options will filter, not after. Applying
        // what a well-formed baseline path names still happens inside
        // filterAndReport() below, which does read the analysis result.
        $projectionOptions = $this->findingFilterOrchestrator->projectionOptions(
            $findingExclusions,
            $input,
            $scopeResolution,
        );
        if ($projectionOptions->baselinePath !== null) {
            BaselineLoader::assertReadable($projectionOptions->baselinePath);
        }

        // Named, and carrying the coverage answer with the paths it is about:
        // rebuilding this positionally lost every field added to the run
        // configuration after the call site was written, silently and once per
        // field.
        $scopedRunConfiguration = $resolvedScope->coversProjectScope
            ? $runConfiguration->coveringProjectScope($scopeResolution->paths)
            : $runConfiguration->narrowedTo($scopeResolution->paths);
        $result = $this->runAnalysis($scopedRunConfiguration, $scopeResolution->fileDiscovery);

        $filterResult = $this->findingFilterOrchestrator->filterAndReport(
            $result,
            $input,
            $output,
            $scopeResolution,
            $projectionOptions,
            $runConfiguration->autoloadDevPolicy,
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
            namespacePattern: $namespacePattern,
            projectScope: $resolvedScope->projectScope,
        );

        return $this->presentProfile($input, $output, $exitCode);
    }

    /**
     * The report is on stdout by now, so whatever ends the run here is
     * presented without a second stdout document, whatever the format.
     */
    private function presentProfile(InputInterface $input, OutputInterface $output, int $exitCode): int
    {
        try {
            $this->resultPresenter->presentProfile($input, $output);
        } catch (ConfigurationRefusal $refusal) {
            return $this->refusalPresenter->refusalAfterPublishedReport($output, $refusal);
        } catch (Throwable $e) {
            return $this->refusalPresenter->internalErrorAfterPublishedReport($output, $e);
        }

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
