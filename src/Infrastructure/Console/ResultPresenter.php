<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console;

use Qualimetrix\Analysis\Finding\Contract\Finding;
use Qualimetrix\Analysis\Run\Contract\Pipeline\AnalysisCoverage;
use Qualimetrix\Analysis\Run\Contract\Pipeline\AnalysisFailure;
use Qualimetrix\Analysis\Run\Contract\Pipeline\AnalysisFailureKind;
use Qualimetrix\Analysis\Run\Contract\Pipeline\AnalysisResult;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Core\Profiler\Contract\ProfilerInterface;
use Qualimetrix\Infrastructure\Git\GitScope;
use Qualimetrix\Reporting\Contract\OutputFormat;
use Qualimetrix\Reporting\CoverageFailure;
use Qualimetrix\Reporting\Filter\FindingFilter;
use Qualimetrix\Reporting\Formatter\FormatterRegistryInterface;
use Qualimetrix\Reporting\Health\SummaryEnricher;
use Qualimetrix\Reporting\ReportBuilder;
use Qualimetrix\Reporting\ReportCoverage;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Handles formatting and output of analysis results.
 */
final class ResultPresenter
{
    private readonly DiagnosticOutput $diagnosticOutput;

    public function __construct(
        private readonly FormatterRegistryInterface $formatterRegistry,
        private readonly ProfilerInterface $profiler,
        private readonly SummaryEnricher $summaryEnricher,
        private readonly ProfilePresenter $profilePresenter,
        private readonly ExitCodeResolver $exitCodeResolver,
        private readonly FindingFilter $findingFilter,
        private readonly FormatterContextFactory $formatterContextFactory,
    ) {
        $this->diagnosticOutput = new DiagnosticOutput();
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
    ): int {
        $profiler = $this->profiler;
        $profiler->start('reporting', 'pipeline');

        $format = $outputFormat->value;

        // Deprecation warning for text-verbose (stderr only, not in formatted output)
        if ($format === 'text-verbose') {
            $this->diagnosticOutput->write(
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
        );

        // Apply --namespace/--class drill-down filter centrally (all formatters benefit)
        $filteredFindings = $this->findingFilter->filterFindings($findings, $context);

        // Build and output report with filtered findings
        $coverage = $this->reportCoverage($analysisResult->coverage, $projectRoot);

        $report = ReportBuilder::create()
            ->addFindings($filteredFindings)
            ->filesAnalyzed($analysisResult->filesAnalyzed)
            ->filesSkipped($analysisResult->filesSkipped)
            ->duration($analysisResult->duration)
            ->metrics($analysisResult->metrics)
            ->namespaceTree($analysisResult->namespaceTree)
            ->coverage($coverage)
            ->build();
        $report = $this->summaryEnricher->enrich($report);
        $formattedOutput = $formatter->format($report, $context);

        $this->writeOutput($formattedOutput, $format, $input, $output);

        $profiler->stop('reporting');

        return $this->exitCodeResolver->resolve($findings, $coverage, $exitPolicy);
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
            $this->failureKind($failure->kind),
            $this->relativizeFailureMessage($failure->message, $projectRoot),
        );
    }

    private function failureKind(AnalysisFailureKind $kind): string
    {
        return $kind->value;
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
        $this->diagnosticOutput->write($output, $message);
    }

    /**
     * Writes formatted output to file (--output) or stdout.
     */
    private function writeOutput(
        string $formattedOutput,
        string $format,
        InputInterface $input,
        OutputInterface $output,
    ): void {
        /** @var string|null $outputPath */
        $outputPath = $input->getOption('output');

        if (\is_string($outputPath) && $outputPath !== '') {
            // Atomic write: tmp file + rename
            $tmpFile = $outputPath . '.tmp.' . getmypid();
            $writeResult = @file_put_contents($tmpFile, $formattedOutput);

            if ($writeResult === false) {
                $this->diagnosticOutput->write(
                    $output,
                    \sprintf('<error>Failed to write output to %s</error>', $outputPath),
                );

                return;
            }

            if (!rename($tmpFile, $outputPath)) {
                $this->diagnosticOutput->write(
                    $output,
                    \sprintf('<error>Failed to rename temporary file to %s</error>', $outputPath),
                );
                if (file_exists($tmpFile)) {
                    unlink($tmpFile);
                }

                return;
            }

            $this->diagnosticOutput->write(
                $output,
                \sprintf('<info>Report written to %s</info>', $outputPath),
            );

            return;
        }

        // TTY warning for HTML output to stdout
        if ($format === 'html' && $this->isOutputTty($output)) {
            $this->diagnosticOutput->write(
                $output,
                '<comment>HTML output is best saved to a file. Use --output=report.html</comment>',
            );
        }

        OutputHelper::write($output, $formattedOutput);
    }

    private function isOutputTty(OutputInterface $output): bool
    {
        if ($output instanceof \Symfony\Component\Console\Output\StreamOutput) {
            return stream_isatty($output->getStream());
        }

        return false;
    }
}
