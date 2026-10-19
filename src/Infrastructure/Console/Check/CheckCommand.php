<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console\Check;

use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Analysis\Configuration\RetiredSuppressionOptions;
use Qualimetrix\Analysis\Run\Contract\Configuration\RunConfiguration;
use Qualimetrix\Analysis\Run\Contract\Pipeline\AnalysisPipelineInterface;
use Qualimetrix\Core\ProductIdentity;
use Qualimetrix\Infrastructure\Console\CheckScope\CheckScopeResolver;
use Qualimetrix\Infrastructure\Console\Invocation\CommandDefinition\FilteredInputDefinition;
use Qualimetrix\Infrastructure\Console\Invocation\CommandLineSpelling;
use Qualimetrix\Infrastructure\Console\Invocation\Configuration\ConfigurationInputAdapter;
use Qualimetrix\Infrastructure\Console\RunSetup\Finding\CheckConfigurationResolvers;
use Qualimetrix\Infrastructure\Console\RunSetup\RuntimeConfigurator;
use Qualimetrix\Infrastructure\Console\RunTarget\RunTargetSession;
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
/**
 * @qmx-threshold coupling.instability 0.923078 -- This subject implements an owner-declared outward protocol with intentionally few incoming readers; the finite boundary preserves visibility of additional dependencies.
 * @qmx-threshold health.cohesion 40.0 -- Independent immutable observations or stateless declaration operations intentionally have no shared instance-field graph; this finite class boundary keeps a further cohesion loss visible.
 */
final class CheckCommand extends Command
{
    /** @var list<string> Rule-specific option names hidden from --help */
    private array $hiddenOptionNames = [];

    public function __construct(
        private readonly AnalysisPipelineInterface $analyzer,
        private readonly RuntimeConfigurator $runtimeConfigurator,
        private readonly \Qualimetrix\Infrastructure\Console\CheckReport\CheckReportDelivery $reportDelivery,
        private readonly \Qualimetrix\Infrastructure\Console\Terminal\Diagnostics\ErrorStream $errorStream,
        private readonly \Qualimetrix\Infrastructure\Console\CacheLifecycle\CacheRunLifecycle $cacheLifecycle,
        private readonly \Qualimetrix\Infrastructure\Console\Invocation\CommandDefinition\CheckCommandDefinition $commandDefinition,
        private readonly CheckScopeResolver $checkScopeResolver,
        private readonly ConfigurationInputAdapter $configurationInputAdapter,
        private readonly CheckConfigurationResolvers $configurationResolvers,
        private readonly RunTargetSession $runTargetSession,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->hiddenOptionNames = $this->commandDefinition->configure($this);
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
     * {@see \Qualimetrix\Infrastructure\Console\Invocation\CommandDefinition\CheckCommandDefinition}, which would put it
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
     * Every return after analysis publishes the profile before the rendered answer.
     * Coordinator OOM exits from shutdown and releases unpublished targets.
     * A profile already published before report publication is not rolled back.
     */
    private function doExecute(InputInterface $input, OutputInterface $output): int
    {
        $this->runtimeConfigurator->resetRunState();

        // Resolve configuration through pipeline. It refuses an option written
        // empty, so it runs before anything below reads `--output` as a path.
        $document = $this->configurationInputAdapter->resolve($input);

        // Refuse an unwritable `--output` before analysis starts. This fast
        // precheck is not a guarantee because writability can change later.
        $response = $this->reportDelivery->prepare($input, $output, $this->runTargetSession);
        $resolved = $this->configurationResolvers->resolve($document);
        $runConfiguration = $resolved->run->runConfiguration;
        $findingConfiguration = $resolved->findingConfiguration;
        $findingExclusions = $resolved->findingExclusions;
        $outputFormat = $resolved->outputFormat;
        $response->bindFormat($outputFormat);
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
            $this->errorStream->write($output, \sprintf(
                '<info>Configuration loaded from: %s</info>',
                implode(', ', $document->appliedSources()),
            ));
        }

        $resolvedScope = $this->checkScopeResolver->resolve($input, $runConfiguration, $document, $output, $findingExclusions);
        $projectionOptions = $resolvedScope->projectionOptions;
        $this->claimRunTargets($input, $output);

        $this->clearCacheIfRequested($input, $output, $resolved->run->cacheConfiguration->directory?->value());

        // Named, and carrying the coverage answer with the paths it is about:
        // rebuilding this positionally lost every field added to the run
        // configuration after the call site was written, silently and once per
        // field.
        $scopedRunConfiguration = $runConfiguration->withProjectScope($resolvedScope->measurement);
        return $response->run(
            fn() => $this->runAnalysis($scopedRunConfiguration),
            $resolvedScope,
            $projectionOptions,
            $scopedRunConfiguration,
            $outputFormat,
            $exitPolicy,
            $this->configurationInputAdapter->publishedDiagnostics($document, $findingConfiguration->diagnostics),
        );
    }

    private function clearCacheIfRequested(InputInterface $input, OutputInterface $output, ?string $cacheDirectory): void
    {
        if ($input->hasOption('clear-cache') && $input->getOption('clear-cache') === true && $cacheDirectory !== null) {
            $this->runTargetSession->targets()->assertCacheClearSafe($cacheDirectory);
        }

        if ($this->cacheLifecycle->clearCacheIfRequested($input)) {
            $this->errorStream->write($output, '<info>Cache cleared.</info>');
        }

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
        $this->errorStream->write($output, \sprintf('<comment>%s</comment>', $message));
    }

}
