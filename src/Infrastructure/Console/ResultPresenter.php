<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console;

use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\FileNamespaceIndex;
use Qualimetrix\Analysis\Finding\Contract\Finding;
use Qualimetrix\Analysis\Finding\Contract\RuleConfigurationInterface;
use Qualimetrix\Analysis\Run\Contract\Pipeline\AnalysisResult;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Core\Pattern\NamespacePattern;
use Qualimetrix\Core\Profiler\Contract\ProfilerInterface;
use Qualimetrix\Infrastructure\Console\RunTarget\RunTargets;
use Qualimetrix\Infrastructure\Git\GitScope;
use Qualimetrix\Reporting\Contract\OutputFormat;
use Qualimetrix\Reporting\DrillDown\DrillDownBinding;
use Qualimetrix\Reporting\DrillDown\FindingFilter;
use Qualimetrix\Reporting\DrillDown\OutOfScopeFindings;
use Qualimetrix\Reporting\FindingProjection\FindingProjectionOptions;
use Qualimetrix\Reporting\FindingProjection\FindingProjectionResult;
use Qualimetrix\Reporting\FindingProjection\SuppressionCompositionBuilder;
use Qualimetrix\Reporting\Formatter\FormattedReport;
use Qualimetrix\Reporting\Formatter\FormatterRegistryInterface;
use Qualimetrix\Reporting\Formatter\Prose\ProseText;
use Qualimetrix\Reporting\Formatter\PublicationKind;
use Qualimetrix\Reporting\Formatter\PublishedUtf8;
use Qualimetrix\Reporting\FormatterContext;
use Qualimetrix\Reporting\Health\SummaryEnricher;
use Qualimetrix\Reporting\ReportBuilder;
use Qualimetrix\Reporting\ReportProjectScope;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Handles formatting and output of analysis results.
 *
 * @qmx-threshold code-smell.constructor-overinjection warning=10 error=10 -- Raw 9 gets one-edge
 *                headroom. `RuleConfigurationInterface` is the eighth collaborator, added so this
 *                class can build {@see \Qualimetrix\Reporting\FindingProjection\SuppressionComposition}
 *                without a second, separately-wired service reaching the same per-rule
 *                exclusion predicates {@see \Qualimetrix\Reporting\FindingProjection\SuppressionCompositionBuilder}
 *                needs. The ninth, {@see ErrorStream}, did not add a collaborator: this class always
 *                had one for its six stderr messages and built it privately, which is precisely what
 *                made the error stream have two owners. Making it a parameter is what allows the
 *                single shared instance; hiding it again would restore the defect.
 */
final class ResultPresenter
{
    private readonly SuppressionCompositionBuilder $suppressionCompositionBuilder;

    public function __construct(
        private readonly FormatterRegistryInterface $formatterRegistry,
        private readonly ProfilerInterface $profiler,
        private readonly SummaryEnricher $summaryEnricher,
        private readonly ProfilePresenter $profilePresenter,
        private readonly ExitCodeResolver $exitCodeResolver,
        private readonly FindingFilter $findingFilter,
        private readonly FormatterContextFactory $formatterContextFactory,
        private readonly RuleConfigurationInterface $ruleConfiguration,
        private readonly ErrorStream $errorStream,
    ) {
        $this->suppressionCompositionBuilder = new SuppressionCompositionBuilder();
    }

    /**
     * Outputs formatted results and returns exit code.
     *
     * @param list<Finding> $findings
     * @param list<array{message: string, source: list<array<string, mixed>>}> $configurationDiagnostics
     */
    public function presentResults(
        array $findings,
        AnalysisResult $analysisResult,
        InputInterface $input,
        OutputInterface $output,
        AbsolutePath $projectRoot,
        RunTargets $runTargets,
        OutputFormat $outputFormat,
        ExitPolicy $exitPolicy,
        ?GitScope $reportScope = null,
        ?FindingProjectionResult $filterResult = null,
        ?FindingProjectionOptions $projectionOptions = null,
        ?NamespacePattern $namespacePattern = null,
        ?ReportProjectScope $projectScope = null,
        array $configurationDiagnostics = [],
    ): int {
        $profiler = $this->profiler;
        $profiler->start('reporting', 'pipeline');

        $format = $outputFormat->value;

        $formatter = $this->formatterRegistry->get($format);
        $context = $this->formatterContextFactory->create(
            $input,
            $output,
            $formatter,
            $projectRoot,
            $reportScope !== null,
            $namespacePattern,
        );

        $this->assertDrillDownBinds($context, $analysisResult);

        // Apply --namespace/--class drill-down filter centrally (all formatters benefit)
        $filteredFindings = $this->findingFilter->filterFindings($findings, $context, FileNamespaceIndex::fromRepository($analysisResult->measured->repository));

        // Build and output report with filtered findings
        $coverage = ReportCoverageProjection::of($analysisResult->measured->coverage, $projectRoot);

        $reportBuilder = ReportBuilder::create()
            ->addFindings($filteredFindings)
            ->filesAnalyzed($analysisResult->measured->coverage->analyzedFilesCount())
            ->filesSkipped($analysisResult->measured->coverage->skippedFilesCount())
            ->duration($analysisResult->measured->duration)
            ->metrics($analysisResult->measured->repository)
            ->namespaceTree($analysisResult->measured->namespaceTree)
            ->coverage($coverage)
            ->configurationDiagnostics($configurationDiagnostics);

        if ($context->namespace !== null || $context->class !== null) {
            $reportBuilder->outOfScope(OutOfScopeFindings::between($findings, $filteredFindings));
        }
        if ($projectScope !== null) {
            $reportBuilder->projectScope($projectScope);
        }

        $this->attachSuppressionComposition($reportBuilder, $format, $input, $analysisResult, $filterResult, $projectionOptions);

        $report = $reportBuilder->build();
        $report = $this->summaryEnricher->enrich($report);
        $formattedOutput = $formatter->format($report, $context);
        if ($formatter->publicationKind() === PublicationKind::Prose) {
            $published = ProseText::publish($formattedOutput->body, $this->errorStream->glyphMode());
            $formattedOutput = new FormattedReport($published->body, $formattedOutput->escapedStrings + $published->escapedStrings);
        }
        if ($formattedOutput->escapedStrings > 0) {
            $this->errorStream->write($output, PublishedUtf8::describe($formattedOutput->escapedStrings));
        }

        $this->writeOutput($formattedOutput, $format, $input, $output, $runTargets);

        $profiler->stop('reporting');

        return $this->exitCodeResolver->resolve($findings, $coverage, $exitPolicy);
    }

    /**
     * Builds what the `suppressed` format and `--show-suppressed` publish;
     * left out of every other run, because the per-rule ledger it reads costs
     * memory only those two ask for.
     */
    private function attachSuppressionComposition(
        ReportBuilder $reportBuilder,
        string $format,
        InputInterface $input,
        AnalysisResult $analysisResult,
        ?FindingProjectionResult $filterResult,
        ?FindingProjectionOptions $projectionOptions,
    ): void {
        $showSuppressed = $input->hasOption('show-suppressed') && $input->getOption('show-suppressed') === true;
        if (
            ($format === 'suppressed' || $showSuppressed)
            && $filterResult !== null
            && $projectionOptions !== null
            && $analysisResult->ruleExecution !== null
        ) {
            $reportBuilder->suppressionComposition($this->suppressionCompositionBuilder->build(
                $filterResult,
                $analysisResult->ruleExecution,
                $this->ruleConfiguration,
                $projectionOptions,
            ));
        }
    }

    /**
     * The presentation door the command opens before the analysis: it binds
     * every output option whose value can be judged without the run
     * ({@see FormatterContextFactory::bindBeforeAnalysis()}), so a mistyped
     * `--detail`, `--top`, `--group-by` or `--format-opt` value costs no
     * analysis, and answers with the `--namespace` pattern the report is
     * drilled down to.
     */
    public function bindOutputOptions(InputInterface $input): ?NamespacePattern
    {
        return $this->formatterContextFactory->bindBeforeAnalysis($input);
    }

    /** The half of {@see self::bindOutputOptions()} that needs the resolved format a configuration may select. */
    public function bindOutputFormat(InputInterface $input, OutputFormat $outputFormat): void
    {
        $this->formatterContextFactory->bindFormatBeforeAnalysis($input, $outputFormat->value);
    }

    /**
     * Refuses a `--namespace` or `--class` value that selects nothing.
     *
     * These are presentation filters, so a value naming a subtree the run never
     * saw does not make the analysis incomplete — it empties the report, which
     * reads exactly like a clean subtree. The check runs before the report is
     * built, and the refusal travels the same route every other one does:
     * {@see \Qualimetrix\Infrastructure\Console\Command\CheckCommand::execute()}
     * wraps the whole run, so a refusal raised after the analysis still exits 3.
     *
     * The mutually-exclusive pair is settled earlier, by
     * {@see FormatterContextFactory}, which is why at most one branch can fire.
     */
    private function assertDrillDownBinds(FormatterContext $context, AnalysisResult $analysisResult): void
    {
        $binding = new DrillDownBinding();
        $metrics = $analysisResult->measured->repository;

        if ($context->namespace !== null
            && $binding->namespaceBindings($context->namespace, $metrics, $analysisResult->measured->namespaceTree) === 0
        ) {
            throw ConfigurationRefusal::aboutCommandLineInput('--namespace', \sprintf(
                'Namespace "%s" matched none of the %d analyzed namespaces and symbol names it is compared against. '
                . 'The report would be empty because nothing was selected, not because nothing was found.',
                $context->namespace->definition->display(),
                $binding->namespaceUniverseSize($metrics, $analysisResult->measured->namespaceTree),
            ));
        }

        if ($context->class !== null && $binding->classBindings($context->class, $metrics) === 0) {
            throw ConfigurationRefusal::aboutCommandLineInput('--class', \sprintf(
                'Class "%s" matched none of the %d classes in the analyzed code. '
                . 'The report would be empty because nothing was selected, not because nothing was found.',
                $context->class,
                $binding->classUniverseSize($metrics),
            ));
        }
    }

    /**
     * Outputs profiling results if profiling was enabled.
     */
    public function presentProfile(InputInterface $input, OutputInterface $output, RunTargets $runTargets): void
    {
        $this->profilePresenter->present($input, $output, $runTargets);
    }

    public function writeDiagnostic(OutputInterface $output, string $message): void
    {
        $this->errorStream->write($output, $message);
    }

    /**
     * Refuses an `--output` target the report cannot be written to, before
     * analysis runs. The run target judgement checks access without writing.
     */
    public function assertOutputIsWritable(InputInterface $input, RunTargets $runTargets): void
    {
        $path = CommandLineSpelling::option($input, 'output');
        if ($path !== null) {
            $runTargets->judge('--output', $path);
        }
    }

    /**
     * Writes formatted output to file (--output) or stdout.
     *
     * A write that fails here, after the precheck passed, carries a
     * typed environment refusal rather than reporting success with an
     * undelivered report: the refusal beats whatever exit code the findings
     * would have produced, which is why this throws instead of returning a
     * status for the caller to reconcile with `ExitCodeResolver`.
     */
    private function writeOutput(
        FormattedReport $formattedOutput,
        string $format,
        InputInterface $input,
        OutputInterface $output,
        RunTargets $runTargets,
    ): void {
        $target = CommandLineSpelling::option($input, 'output');

        if ($target !== null) {
            $runTargets->write('--output', $formattedOutput->body);

            $this->errorStream->write(
                $output,
                \sprintf('<info>Report written to %s</info>', $target),
            );

            return;
        }

        // TTY warning for HTML output to stdout
        if ($format === 'html' && $this->isOutputTty($output)) {
            $this->errorStream->write(
                $output,
                '<comment>HTML output is best saved to a file. Use --output=report.html</comment>',
            );
        }

        OutputHelper::write($output, $formattedOutput->body);
    }

    private function isOutputTty(OutputInterface $output): bool
    {
        return $output instanceof \Symfony\Component\Console\Output\StreamOutput && stream_isatty($output->getStream());
    }
}
