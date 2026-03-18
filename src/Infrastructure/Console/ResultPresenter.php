<?php

declare(strict_types=1);

namespace AiMessDetector\Infrastructure\Console;

use AiMessDetector\Analysis\Pipeline\AnalysisResult;
use AiMessDetector\Baseline\BaselineGenerator;
use AiMessDetector\Baseline\BaselineWriter;
use AiMessDetector\Configuration\AnalysisConfiguration;
use AiMessDetector\Configuration\ConfigurationProviderInterface;
use AiMessDetector\Core\Profiler\ProfilerHolder;
use AiMessDetector\Core\Violation\Severity;
use AiMessDetector\Core\Violation\Violation;
use AiMessDetector\Reporting\Formatter\FormatterRegistryInterface;
use AiMessDetector\Reporting\Health\SummaryEnricher;
use AiMessDetector\Reporting\ReportBuilder;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Handles formatting, output of analysis results and profiler export.
 */
final class ResultPresenter
{
    public function __construct(
        private readonly FormatterRegistryInterface $formatterRegistry,
        private readonly ProfilerHolder $profilerHolder,
        private readonly BaselineGenerator $baselineGenerator,
        private readonly BaselineWriter $baselineWriter,
        private readonly ConfigurationProviderInterface $configurationProvider,
        private readonly SummaryEnricher $summaryEnricher,
        private readonly ProfilePresenter $profilePresenter,
        private readonly FormatterContextFactory $formatterContextFactory = new FormatterContextFactory(),
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
        bool $partialAnalysis = false,
        bool $baselineGenerated = false,
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
        $context = $this->formatterContextFactory->create($input, $output, $formatter, $partialAnalysis);

        // Build and output report with filtered violations
        $report = ReportBuilder::create()
            ->addViolations($violations)
            ->filesAnalyzed($analysisResult->filesAnalyzed)
            ->filesSkipped($analysisResult->filesSkipped)
            ->duration($analysisResult->duration)
            ->metrics($analysisResult->metrics)
            ->build();
        $report = $this->summaryEnricher->enrich($report, $partialAnalysis);
        $formattedOutput = $formatter->format($report, $context);

        $this->writeOutput($formattedOutput, $format, $input, $output);

        $profiler->stop('reporting');

        // When a baseline was successfully written, the purpose was to capture current state —
        // not to assert clean code. Override exit code to 0 regardless of violation count.
        if ($baselineGenerated) {
            return 0;
        }

        return $this->determineExitCode($violations);
    }

    /**
     * Outputs profiling results if profiling was enabled.
     */
    public function presentProfile(InputInterface $input, OutputInterface $output): void
    {
        $this->profilePresenter->present($input, $output);
    }

    /**
     * Generates baseline file if requested.
     *
     * Returns true when a baseline was successfully written, false when skipped.
     *
     * @param list<Violation> $violations
     */
    public function generateBaselineIfRequested(
        array $violations,
        InputInterface $input,
        OutputInterface $output,
    ): bool {
        $generateBaselinePath = $input->getOption('generate-baseline');
        if (!\is_string($generateBaselinePath) || $generateBaselinePath === '') {
            return false;
        }

        $baseline = $this->baselineGenerator->generate($violations);
        $this->baselineWriter->write($baseline, $generateBaselinePath);

        $output->writeln(\sprintf(
            '<info>Baseline with %d violations written to %s</info>',
            $baseline->count(),
            $generateBaselinePath,
        ));

        return true;
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
        if ($format === 'health' && $this->isOutputTty($output)) {
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

    /**
     * Determines exit code based on violation severity and failOn configuration.
     *
     * When failOn is Severity::Error, only errors cause non-zero exit code (warnings are ignored).
     * When failOn is null or Severity::Warning, current behavior is preserved.
     *
     * @param list<Violation> $violations
     */
    private function determineExitCode(array $violations): int
    {
        $failOn = $this->configurationProvider->hasConfiguration()
            ? $this->configurationProvider->getConfiguration()->failOn
            : null;

        // --fail-on=none: never fail on violations
        if ($failOn === false) {
            return 0;
        }

        $hasErrors = false;
        $hasWarnings = false;

        foreach ($violations as $violation) {
            if ($violation->severity === Severity::Error) {
                $hasErrors = true;
                break;
            }
            if ($violation->severity === Severity::Warning) {
                $hasWarnings = true;
            }
        }

        if ($hasErrors) {
            return Severity::Error->getExitCode();
        }

        if ($hasWarnings && $failOn !== Severity::Error) {
            return Severity::Warning->getExitCode();
        }

        return 0;
    }
}
