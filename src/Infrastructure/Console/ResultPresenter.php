<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console;

use Qualimetrix\Analysis\Configuration\Contract\TransitionalRuntimeConfiguration;
use Qualimetrix\Analysis\Configuration\Contract\TransitionalRuntimeConfigurationProviderInterface;
use Qualimetrix\Analysis\Run\Contract\Pipeline\AnalysisCoverage;
use Qualimetrix\Analysis\Run\Contract\Pipeline\AnalysisFailure;
use Qualimetrix\Analysis\Run\Contract\Pipeline\AnalysisFailureKind;
use Qualimetrix\Analysis\Run\Contract\Pipeline\AnalysisResult;
use Qualimetrix\Core\Profiler\ProfilerHolder;
use Qualimetrix\Core\Violation\Violation;
use Qualimetrix\Reporting\CoverageFailure;
use Qualimetrix\Reporting\Filter\ViolationFilter;
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
    public function __construct(
        private readonly FormatterRegistryInterface $formatterRegistry,
        private readonly ProfilerHolder $profilerHolder,
        private readonly TransitionalRuntimeConfigurationProviderInterface $configurationProvider,
        private readonly SummaryEnricher $summaryEnricher,
        private readonly ProfilePresenter $profilePresenter,
        private readonly ExitCodeResolver $exitCodeResolver,
        private readonly ViolationFilter $violationFilter,
        private readonly FormatterContextFactory $formatterContextFactory,
        private readonly DiagnosticOutput $diagnosticOutput = new DiagnosticOutput(),
    ) {}

    /**
     * Outputs formatted results and returns exit code.
     *
     * @param list<Violation> $violations
     */
    public function presentResults(
        array $violations,
        AnalysisResult $analysisResult,
        InputInterface $input,
        OutputInterface $output,
        bool $scopedReporting = false,
    ): int {
        $profiler = $this->profilerHolder->get(); // @phpstan-ignore staticMethod.dynamicCall
        $profiler->start('reporting', 'pipeline');

        // Use resolved config format (already merged: defaults -> config file -> CLI)
        // Fall back to CLI option only if config is not yet available
        $format = $this->configurationProvider->hasConfiguration()
            ? $this->configurationProvider->getConfiguration()->format
            : ($input->getOption('format') ?? TransitionalRuntimeConfiguration::DEFAULT_FORMAT);
        /** @var string $format */

        // Deprecation warning for text-verbose (stderr only, not in formatted output)
        if ($format === 'text-verbose') {
            $this->diagnosticOutput->write(
                $output,
                '<comment>Warning: --format=text-verbose is deprecated. Use --format=text --detail instead.</comment>',
            );
        }

        $formatter = $this->formatterRegistry->get($format);
        $context = $this->formatterContextFactory->create($input, $output, $formatter, $scopedReporting);

        // Apply --namespace/--class drill-down filter centrally (all formatters benefit)
        $filteredViolations = $this->violationFilter->filterViolations($violations, $context);

        // Build and output report with filtered violations
        $coverage = $this->reportCoverage($analysisResult->coverage);

        $report = ReportBuilder::create()
            ->addViolations($filteredViolations)
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

        return $this->exitCodeResolver->resolve($violations, $coverage);
    }

    private function reportCoverage(AnalysisCoverage $coverage): ReportCoverage
    {
        return new ReportCoverage(
            discovered: $coverage->discoveredFiles(),
            analyzed: $coverage->analyzedFilesCount(),
            generatedExcluded: $coverage->generatedExcludedFilesCount(),
            failed: $coverage->failedFilesCount(),
            failures: array_map(
                $this->coverageFailure(...),
                $coverage->failures,
            ),
        );
    }

    private function coverageFailure(AnalysisFailure $failure): CoverageFailure
    {
        return new CoverageFailure(
            $failure->path->value(),
            $this->failureKind($failure->kind),
            $this->relativizeFailureMessage($failure->message),
        );
    }

    private function failureKind(AnalysisFailureKind $kind): string
    {
        return $kind->value;
    }

    private function relativizeFailureMessage(string $message): string
    {
        if (!$this->configurationProvider->hasConfiguration()) {
            return $message;
        }

        $root = rtrim($this->configurationProvider->getConfiguration()->projectRoot->value(), '/') . '/';

        return str_replace($root, '', $message);
    }

    /**
     * Outputs profiling results if profiling was enabled.
     */
    public function presentProfile(InputInterface $input, OutputInterface $output): void
    {
        $this->profilePresenter->present($input, $output);
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
