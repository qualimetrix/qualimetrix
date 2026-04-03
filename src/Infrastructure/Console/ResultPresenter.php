<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console;

use Qualimetrix\Analysis\Pipeline\AnalysisResult;
use Qualimetrix\Configuration\AnalysisConfiguration;
use Qualimetrix\Configuration\ConfigurationProviderInterface;
use Qualimetrix\Core\Profiler\ProfilerHolder;
use Qualimetrix\Core\Violation\Violation;
use Qualimetrix\Reporting\Filter\ViolationFilter;
use Qualimetrix\Reporting\Formatter\FormatterRegistryInterface;
use Qualimetrix\Reporting\Health\SummaryEnricher;
use Qualimetrix\Reporting\ReportBuilder;
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
        private readonly ConfigurationProviderInterface $configurationProvider,
        private readonly SummaryEnricher $summaryEnricher,
        private readonly ProfilePresenter $profilePresenter,
        private readonly ExitCodeResolver $exitCodeResolver,
        private readonly ViolationFilter $violationFilter,
        private readonly FormatterContextFactory $formatterContextFactory,
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
        bool $baselineGenerated = false,
        bool $scopedReporting = false,
    ): int {
        $profiler = $this->profilerHolder->get();
        $profiler->start('reporting', 'pipeline');

        // Use resolved config format (already merged: defaults -> config file -> CLI)
        // Fall back to CLI option only if config is not yet available
        $format = $this->configurationProvider->hasConfiguration()
            ? $this->configurationProvider->getConfiguration()->format
            : ($input->getOption('format') ?? AnalysisConfiguration::DEFAULT_FORMAT);
        /** @var string $format */

        // Deprecation warning for text-verbose (stderr only, not in formatted output)
        if ($format === 'text-verbose' && $output instanceof \Symfony\Component\Console\Output\ConsoleOutput) {
            $output->getErrorOutput()->writeln(
                '<comment>Warning: --format=text-verbose is deprecated. Use --format=text --detail instead.</comment>',
            );
        }

        $formatter = $this->formatterRegistry->get($format);
        $context = $this->formatterContextFactory->create($input, $output, $formatter, $scopedReporting);

        // Apply --namespace/--class drill-down filter centrally (all formatters benefit)
        $filteredViolations = $this->violationFilter->filterViolations($violations, $context);

        // Build and output report with filtered violations
        $report = ReportBuilder::create()
            ->addViolations($filteredViolations)
            ->filesAnalyzed($analysisResult->filesAnalyzed)
            ->filesSkipped($analysisResult->filesSkipped)
            ->duration($analysisResult->duration)
            ->metrics($analysisResult->metrics)
            ->namespaceTree($analysisResult->namespaceTree)
            ->build();
        $report = $this->summaryEnricher->enrich($report);
        $formattedOutput = $formatter->format($report, $context);

        $this->writeOutput($formattedOutput, $format, $input, $output);

        $profiler->stop('reporting');

        // When a baseline was successfully written, the purpose was to capture current state —
        // not to assert clean code. Override exit code to 0 regardless of violation count.
        if ($baselineGenerated) {
            return 0;
        }

        return $this->exitCodeResolver->resolve($violations);
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
                $output->writeln(
                    \sprintf('<error>Failed to write output to %s</error>', $outputPath),
                    OutputInterface::OUTPUT_NORMAL | OutputInterface::VERBOSITY_NORMAL,
                );

                return;
            }

            if (!rename($tmpFile, $outputPath)) {
                $output->writeln(
                    \sprintf('<error>Failed to rename temporary file to %s</error>', $outputPath),
                    OutputInterface::OUTPUT_NORMAL | OutputInterface::VERBOSITY_NORMAL,
                );
                if (file_exists($tmpFile)) {
                    unlink($tmpFile);
                }

                return;
            }

            $output->writeln(
                \sprintf('<info>Report written to %s</info>', $outputPath),
                OutputInterface::OUTPUT_NORMAL | OutputInterface::VERBOSITY_NORMAL,
            );

            return;
        }

        // TTY warning for HTML output to stdout
        if ($format === 'html' && $this->isOutputTty($output)) {
            $output->writeln(
                '<comment>HTML output is best saved to a file. Use --output=report.html</comment>',
                OutputInterface::OUTPUT_NORMAL | OutputInterface::VERBOSITY_NORMAL,
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
