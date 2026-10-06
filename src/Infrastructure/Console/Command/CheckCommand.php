<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console\Command;

use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Analysis\Configuration\RetiredSuppressionOptions;
use Qualimetrix\Analysis\Run\Contract\Configuration\RunConfiguration;
use Qualimetrix\Analysis\Run\Contract\Pipeline\AnalysisPipelineInterface;
use Qualimetrix\Core\ProductIdentity;
use Qualimetrix\Infrastructure\Console\AnalysisInputPathValidator;
use Qualimetrix\Infrastructure\Console\CheckConfigurationResolvers;
use Qualimetrix\Infrastructure\Console\CheckScopeResolver;
use Qualimetrix\Infrastructure\Console\CommandLineSpelling;
use Qualimetrix\Infrastructure\Console\ConfigurationInputAdapter;
use Qualimetrix\Infrastructure\Console\FilteredInputDefinition;
use Qualimetrix\Infrastructure\Console\FindingFilterOrchestrator;
use Qualimetrix\Infrastructure\Console\ResultPresenter;
use Qualimetrix\Infrastructure\Console\RuleInputValidator;
use Qualimetrix\Infrastructure\Console\RunTarget\RunTargetSession;
use Qualimetrix\Infrastructure\Console\RuntimeConfigurator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Exception\ExceptionInterface as ConsoleExceptionInterface;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

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
        private readonly RunTargetSession $runTargetSession,
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

                return $this->runTargetSession->refuseBeforeExecution(
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

        return $this->runTargetSession->run($output, $format, fn(): int => $this->doExecute($input, $output));
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
        $this->resultPresenter->assertOutputIsWritable($input, $this->runTargetSession->targets());
        $namespacePattern = $this->resultPresenter->bindOutputOptions($input);
        $resolved = $this->configurationResolvers->resolve($document);
        $runConfiguration = $resolved->run->runConfiguration;
        $findingConfiguration = $this->ruleInputValidator->resolve($document, $input);
        $findingExclusions = $resolved->findingExclusions;
        $outputFormat = $resolved->outputFormat;
        $this->resultPresenter->bindOutputFormat($input, $outputFormat);
        $exitPolicy = $this->configurationInputAdapter->exitPolicy($document);

        // Configure runtime using resolved config
        $this->runtimeConfigurator->configure(
            $document,
            $resolved->run,
            $findingConfiguration,
            $input,
            $output,
        );

        $this->configurationInputAdapter->writeDiagnostics($document, $output, $findingConfiguration->diagnostics);
        if ($output->isVerbose() && $document->appliedSources() !== []) {
            $this->resultPresenter->writeDiagnostic($output, \sprintf(
                '<info>Configuration loaded from: %s</info>',
                implode(', ', $document->appliedSources()),
            ));
        }

        $resolvedScope = $this->checkScopeResolver->resolve($input, $runConfiguration);
        $scopeResolution = $resolvedScope->scope;

        (new AnalysisInputPathValidator())->validate(
            $scopeResolution->paths,
            $document,
            $runConfiguration->projectScope->universe->pathsAuthored
                ? \Qualimetrix\Analysis\Run\Contract\Configuration\PathsAuthorship::Authored
                : \Qualimetrix\Analysis\Run\Contract\Configuration\PathsAuthorship::Inferred,
        );

        $projectRoot = $runConfiguration->projectRoot;
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
        $this->claimRunTargets($input, $output);

        if ($input->hasOption('clear-cache') && $input->getOption('clear-cache') === true) {
            $this->runTargetSession->targets()->assertCacheClearSafe($resolved->run->cacheConfiguration->directory->value());
        }

        if ($this->runtimeConfigurator->clearCacheIfRequested($input)) {
            $this->resultPresenter->writeDiagnostic($output, '<info>Cache cleared.</info>');
        }

        // Named, and carrying the coverage answer with the paths it is about:
        // rebuilding this positionally lost every field added to the run
        // configuration after the call site was written, silently and once per
        // field.
        $scopedRunConfiguration = $runConfiguration->withProjectScope($resolvedScope->measurement);
        $result = $this->runAnalysis($scopedRunConfiguration);

        $filterResult = $this->findingFilterOrchestrator->filterAndReport(
            $result,
            $input,
            $output,
            $resolvedScope,
            $projectionOptions,
            $scopedRunConfiguration,
        );
        $filteredFindings = $filterResult->findings;
        $this->runTargetSession->targets()->settle();

        // `check` no longer writes baselines — `bin/qmx baseline:generate` does —
        // so ResultPresenter no longer has a "baseline was just captured, report
        // success regardless" path to opt into here.
        $exitCode = $this->resultPresenter->presentResults(
            $filteredFindings,
            $result,
            $input,
            $output,
            $projectRoot,
            runTargets: $this->runTargetSession->targets(),
            outputFormat: $outputFormat,
            exitPolicy: $exitPolicy,
            reportScope: $scopeResolution->reportScope,
            filterResult: $filterResult,
            projectionOptions: $projectionOptions,
            namespacePattern: $namespacePattern,
            projectScope: $this->findingFilterOrchestrator->projectScope($resolvedScope, $result, $projectionOptions),
            configurationDiagnostics: $this->configurationInputAdapter->publishedDiagnostics($document, $findingConfiguration->diagnostics),
        );

        $this->runTargetSession->markOutputPublished();

        return $this->presentProfile($input, $output, $exitCode);
    }

    private function claimRunTargets(InputInterface $input, OutputInterface $output): void
    {
        $profile = $input->hasOption('profile') ? $input->getOption('profile') : false;
        if ($profile !== false && $profile !== null && $profile !== true) {
            $this->runTargetSession->targets()->judge('--profile', CommandLineSpelling::of($profile, '--profile'));
        }
        if (CommandLineSpelling::option($input, 'output') === null) {
            $this->runTargetSession->targets()->reportOnStandardOutput();
        }
        $inputs = [];
        foreach (['config', 'baseline'] as $name) {
            $spelling = CommandLineSpelling::option($input, $name);
            if ($spelling !== null) {
                $inputs['--' . $name] = $spelling;
            }
        }
        $this->runTargetSession->targets()->assertSeparateFromInputs($inputs);
        foreach ($this->runTargetSession->targets()->exposureWarnings() as $warning) {
            $this->writeWarning($output, 'Warning: ' . \Symfony\Component\Console\Formatter\OutputFormatter::escape($warning));
        }

        $this->runTargetSession->targets()->claim();
    }

    /**
     * The report is on stdout by now, so whatever ends the run here is
     * presented without a second stdout document, whatever the format.
     */
    private function presentProfile(InputInterface $input, OutputInterface $output, int $exitCode): int
    {
        return $this->runTargetSession->afterPublishedReport(function () use ($input, $output): void {
            $this->resultPresenter->presentProfile($input, $output, $this->runTargetSession->targets());
            $this->runTargetSession->targets()->settle();
        }, $exitCode);
    }

    /**
     * Runs the analysis on specified paths.
     */
    private function runAnalysis(RunConfiguration $configuration): \Qualimetrix\Analysis\Run\Contract\Pipeline\AnalysisResult
    {
        return $this->analyzer->analyze($configuration);
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
