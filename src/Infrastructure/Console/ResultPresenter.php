<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console;

use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Analysis\Finding\Contract\Finding;
use Qualimetrix\Analysis\Finding\Contract\RuleConfigurationInterface;
use Qualimetrix\Analysis\Run\Contract\Pipeline\AnalysisCoverage;
use Qualimetrix\Analysis\Run\Contract\Pipeline\AnalysisFailure;
use Qualimetrix\Analysis\Run\Contract\Pipeline\AnalysisResult;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Core\Pattern\NamespacePattern;
use Qualimetrix\Core\Profiler\Contract\ProfilerInterface;
use Qualimetrix\Infrastructure\Git\GitScope;
use Qualimetrix\Reporting\Contract\OutputFormat;
use Qualimetrix\Reporting\CoverageFailure;
use Qualimetrix\Reporting\DrillDown\DrillDownBinding;
use Qualimetrix\Reporting\DrillDown\FindingFilter;
use Qualimetrix\Reporting\DrillDown\OutOfScopeFindings;
use Qualimetrix\Reporting\FindingProjection\FindingProjectionOptions;
use Qualimetrix\Reporting\FindingProjection\FindingProjectionResult;
use Qualimetrix\Reporting\FindingProjection\SuppressionCompositionBuilder;
use Qualimetrix\Reporting\Formatter\FormatterRegistryInterface;
use Qualimetrix\Reporting\FormatterContext;
use Qualimetrix\Reporting\Health\SummaryEnricher;
use Qualimetrix\Reporting\ReportBuilder;
use Qualimetrix\Reporting\ReportCoverage;
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
     */
    public function presentResults(
        array $findings,
        AnalysisResult $analysisResult,
        InputInterface $input,
        OutputInterface $output,
        AbsolutePath $projectRoot,
        OutputFormat $outputFormat,
        ExitPolicy $exitPolicy,
        ?GitScope $reportScope = null,
        ?FindingProjectionResult $filterResult = null,
        ?FindingProjectionOptions $projectionOptions = null,
        ?NamespacePattern $namespacePattern = null,
        ?ReportProjectScope $projectScope = null,
    ): int {
        $profiler = $this->profiler;
        $profiler->start('reporting', 'pipeline');

        $format = $outputFormat->value;

        // Deprecation warning for text-verbose (stderr only, not in formatted output)
        if ($format === 'text-verbose') {
            $this->errorStream->write(
                $output,
                '<comment>Warning: --format=text-verbose is deprecated. Use --format=text --detail instead.</comment>',
            );
        }

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
        $filteredFindings = $this->findingFilter->filterFindings($findings, $context);

        // Build and output report with filtered findings
        $coverage = $this->reportCoverage($analysisResult->coverage, $projectRoot);

        $reportBuilder = ReportBuilder::create()
            ->addFindings($filteredFindings)
            ->filesAnalyzed($analysisResult->filesAnalyzed)
            ->filesSkipped($analysisResult->filesSkipped)
            ->duration($analysisResult->duration)
            ->metrics($analysisResult->metrics)
            ->namespaceTree($analysisResult->namespaceTree)
            ->coverage($coverage);

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

        $this->writeOutput($formattedOutput, $format, $input, $output);

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
                $analysisResult->suppressions,
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
        $metrics = $analysisResult->metrics;

        if ($context->namespace !== null
            && $binding->namespaceBindings($context->namespace, $metrics, $analysisResult->namespaceTree) === 0
        ) {
            throw ConfigurationRefusal::aboutCommandLineInput('--namespace', \sprintf(
                'Namespace "%s" matched none of the %d analyzed namespaces and symbol names it is compared against. '
                . 'The report would be empty because nothing was selected, not because nothing was found.',
                $context->namespace->definition->display(),
                $binding->namespaceUniverseSize($metrics, $analysisResult->namespaceTree),
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

    private function reportCoverage(AnalysisCoverage $coverage, AbsolutePath $projectRoot): ReportCoverage
    {
        return new ReportCoverage(
            discovered: $coverage->discoveredFiles(),
            analyzed: $coverage->analyzedFilesCount(),
            generatedExcluded: $coverage->generatedExcludedFilesCount(),
            failed: $coverage->failedFilesCount(),
            failures: array_map(
                fn(AnalysisFailure $failure): CoverageFailure => $this->coverageFailure($failure, $projectRoot),
                $coverage->failures,
            ),
        );
    }

    private function coverageFailure(AnalysisFailure $failure, AbsolutePath $projectRoot): CoverageFailure
    {
        return new CoverageFailure(
            $failure->path->value(),
            $failure->kind->value,
            $this->relativizeFailureMessage($failure->message, $projectRoot),
        );
    }

    private function relativizeFailureMessage(string $message, AbsolutePath $projectRoot): string
    {
        $prefix = rtrim($projectRoot->value(), '/');
        if ($prefix === '') {
            return $message;
        }

        return preg_replace(
            '#(?<![A-Za-z0-9._~/\\-])' . preg_quote($prefix, '#') . '/#',
            '',
            $message,
        ) ?? $message;
    }

    /**
     * Outputs profiling results if profiling was enabled.
     */
    public function presentProfile(InputInterface $input, OutputInterface $output): void
    {
        $this->profilePresenter->present($input, $output);
    }

    public function writeDiagnostic(OutputInterface $output, string $message): void
    {
        $this->errorStream->write($output, $message);
    }

    /**
     * Refuses an `--output` target the report cannot be written to, before
     * analysis runs; {@see ArtifactFile} judges it by the write it makes.
     */
    public function assertOutputIsWritable(InputInterface $input): void
    {
        self::outputTarget($input)?->refuseUnwritable();
    }

    private static function outputTarget(InputInterface $input): ?ArtifactFile
    {
        // `--output=` never reaches here: the configuration adapter refuses
        // an option written empty before the command reads this one.
        $path = CommandLineSpelling::option($input, 'output');

        return $path === null ? null : new ArtifactFile($path, '--output');
    }

    /**
     * Writes formatted output to file (--output) or stdout.
     *
     * A write that fails here, after the precheck passed, carries a
     * {@see ConfigurationRefusal} rather than reporting success with an
     * undelivered report: the refusal beats whatever exit code the findings
     * would have produced, which is why this throws instead of returning a
     * status for the caller to reconcile with `ExitCodeResolver`.
     */
    private function writeOutput(
        string $formattedOutput,
        string $format,
        InputInterface $input,
        OutputInterface $output,
    ): void {
        $target = self::outputTarget($input);

        if ($target !== null) {
            $target->write($formattedOutput);

            $this->errorStream->write(
                $output,
                \sprintf('<info>Report written to %s</info>', $target->path),
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

        OutputHelper::write($output, $formattedOutput);
    }

    private function isOutputTty(OutputInterface $output): bool
    {
        return $output instanceof \Symfony\Component\Console\Output\StreamOutput && stream_isatty($output->getStream());
    }
}
