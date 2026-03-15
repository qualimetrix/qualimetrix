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
use AiMessDetector\Reporting\Formatter\FormatterInterface;
use AiMessDetector\Reporting\Formatter\FormatterRegistryInterface;
use AiMessDetector\Reporting\FormatterContext;
use AiMessDetector\Reporting\GroupBy;
use AiMessDetector\Reporting\ReportBuilder;
use AiMessDetector\Reporting\SummaryEnricher;
use InvalidArgumentException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use ValueError;

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
    ): int {
        $profiler = $this->profilerHolder->get();
        $profiler->start('reporting', 'pipeline');

        // Use resolved config format (already merged: defaults → config file → CLI)
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
        $context = $this->buildFormatterContext($input, $output, $formatter, $partialAnalysis);

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

        return $this->determineExitCode($violations);
    }

    /**
     * Outputs profiling results if profiling was enabled.
     */
    public function presentProfile(InputInterface $input, OutputInterface $output): void
    {
        $profiler = $this->profilerHolder->get();

        if (!$profiler->isEnabled()) {
            return;
        }

        $profileOption = $input->getOption('profile');

        // If --profile without value, output summary to stderr
        if ($profileOption === null) {
            $summary = $this->formatProfileSummary($profiler->getSummary());
            $output->writeln('', OutputInterface::OUTPUT_NORMAL | OutputInterface::VERBOSITY_NORMAL);
            $output->writeln($summary, OutputInterface::OUTPUT_NORMAL | OutputInterface::VERBOSITY_NORMAL);

            return;
        }

        // Export to file
        /** @var string $formatOption */
        $formatOption = $input->getOption('profile-format') ?? 'json';

        // Validate format
        if (!\in_array($formatOption, ['json', 'chrome-tracing'], true)) {
            $output->writeln(
                \sprintf('<error>Invalid profile format: %s. Valid formats: json, chrome-tracing</error>', $formatOption),
                OutputInterface::OUTPUT_NORMAL | OutputInterface::VERBOSITY_NORMAL,
            );

            return;
        }

        /** @var 'json'|'chrome-tracing' $format */
        $format = $formatOption;

        $profileData = $profiler->export($format);

        // Atomic write: write to temp file first, then rename
        $tmpFile = $profileOption . '.tmp.' . getmypid();
        $writeResult = @file_put_contents($tmpFile, $profileData);

        if ($writeResult === false) {
            $output->writeln(
                \sprintf('<error>Failed to write profile data to temporary file %s</error>', $tmpFile),
                OutputInterface::OUTPUT_NORMAL | OutputInterface::VERBOSITY_NORMAL,
            );

            return;
        }

        if (!rename($tmpFile, $profileOption)) {
            $output->writeln(
                \sprintf('<error>Failed to rename temporary profile file %s to %s</error>', $tmpFile, $profileOption),
                OutputInterface::OUTPUT_NORMAL | OutputInterface::VERBOSITY_NORMAL,
            );

            // Clean up temp file on rename failure
            if (file_exists($tmpFile)) {
                unlink($tmpFile);
            }

            return;
        }

        $output->writeln(
            \sprintf('<info>Profile exported to %s</info>', $profileOption),
            OutputInterface::OUTPUT_NORMAL | OutputInterface::VERBOSITY_NORMAL,
        );
    }

    /**
     * Generates baseline file if requested.
     *
     * @param list<Violation> $violations
     */
    public function generateBaselineIfRequested(
        array $violations,
        InputInterface $input,
        OutputInterface $output,
    ): void {
        $generateBaselinePath = $input->getOption('generate-baseline');
        if (!\is_string($generateBaselinePath) || $generateBaselinePath === '') {
            return;
        }

        $baseline = $this->baselineGenerator->generate($violations);
        $this->baselineWriter->write($baseline, $generateBaselinePath);

        $output->writeln(\sprintf(
            '<info>Baseline with %d violations written to %s</info>',
            \count($violations),
            $generateBaselinePath,
        ));
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

    private function buildFormatterContext(
        InputInterface $input,
        OutputInterface $output,
        FormatterInterface $formatter,
        bool $partialAnalysis = false,
    ): FormatterContext {
        // Resolve group-by: explicit CLI option or formatter default
        /** @var string|null $groupByValue */
        $groupByValue = $input->getOption('group-by');
        $isGroupByExplicit = $groupByValue !== null;
        try {
            $groupBy = $isGroupByExplicit
                ? GroupBy::from($groupByValue)
                : $formatter->getDefaultGroupBy();
        } catch (ValueError) {
            $valid = implode(', ', array_column(GroupBy::cases(), 'value'));
            throw new InvalidArgumentException(\sprintf(
                'Invalid --group-by value "%s". Valid values: %s',
                $groupByValue,
                $valid,
            ));
        }

        // Parse --format-opt key=value pairs
        /** @var list<string> $formatOpts */
        $formatOpts = $input->getOption('format-opt');
        $options = [];
        foreach ($formatOpts as $opt) {
            $eqPos = strpos($opt, '=');
            if ($eqPos === false) {
                throw new InvalidArgumentException(\sprintf(
                    'Invalid --format-opt value "%s": expected format key=value',
                    $opt,
                ));
            }
            $options[substr($opt, 0, $eqPos)] = substr($opt, $eqPos + 1);
        }

        // Parse --namespace and --class (mutually exclusive)
        /** @var string|null $namespaceFilter */
        $namespaceFilter = $input->getOption('namespace');
        /** @var string|null $classFilter */
        $classFilter = $input->getOption('class');

        if ($namespaceFilter !== null && $classFilter !== null) {
            throw new InvalidArgumentException('Options --namespace and --class are mutually exclusive');
        }

        $terminalWidth = (new \Symfony\Component\Console\Terminal())->getWidth() ?: 80;
        $detail = (bool) $input->getOption('detail') || $namespaceFilter !== null || $classFilter !== null;

        return new FormatterContext(
            useColor: $output->isDecorated(),
            groupBy: $groupBy,
            options: $options,
            basePath: getcwd() ?: '.',
            partialAnalysis: $partialAnalysis,
            namespace: $namespaceFilter,
            class: $classFilter,
            terminalWidth: $terminalWidth,
            detail: $detail,
            isGroupByExplicit: $isGroupByExplicit,
        );
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

    /**
     * Formats profiling summary for console output.
     *
     * @param array<string, array{total: float, count: int, avg: float, memory: int, peak_memory: int}> $summary
     */
    private function formatProfileSummary(array $summary): string
    {
        if ($summary === []) {
            return '<comment>No profiling data available</comment>';
        }

        // Calculate total time by summing all span durations.
        // Note: percentages may sum to >100% due to overlapping/nested spans.
        // This is expected — each span's percentage shows its share of total measured work,
        // not of wall-clock time.
        $totalTime = 0.0;
        foreach ($summary as $stat) {
            $totalTime += $stat['total'];
        }

        // Sort by total time descending
        uasort($summary, fn($a, $b) => $b['total'] <=> $a['total']);

        // Filter out spans contributing less than 1% of the longest span's duration.
        // Using max span (≈ wall-clock) instead of totalTime (sum of all including nested)
        // to avoid hiding meaningful phases.
        $maxTime = max(array_column($summary, 'total'));
        $threshold = $maxTime * 0.01;
        $filtered = array_filter($summary, static fn($stat) => $stat['total'] >= $threshold);
        $hiddenCount = \count($summary) - \count($filtered);

        $lines = ['<comment>Profile summary:</comment>'];

        foreach ($filtered as $name => $stat) {
            $percentage = $totalTime > 0 ? ($stat['total'] / $totalTime) * 100 : 0;
            $memoryDelta = $this->formatBytes($stat['memory']);
            $peakMemory = $this->formatBytes($stat['peak_memory']);

            $lines[] = \sprintf(
                '  <info>%s</info>: %.3fs (%3.0f%%) | Δ%s | ↑%s | %dx',
                str_pad($name, 15),
                $stat['total'] / 1000, // ms to s
                $percentage,
                str_pad($memoryDelta, 8),
                str_pad($peakMemory, 8),
                $stat['count'],
            );
        }

        if ($hiddenCount > 0) {
            $lines[] = \sprintf('  <comment>... and %d more spans below 1%%</comment>', $hiddenCount);
        }

        // Add peak memory
        $peakMemory = memory_get_peak_usage(true);
        $lines[] = \sprintf('<comment>Peak memory:</comment> %s', $this->formatBytes($peakMemory));

        return implode("\n", $lines);
    }

    /**
     * Formats bytes to human-readable format.
     */
    private function formatBytes(int $bytes): string
    {
        if ($bytes < 0) {
            return \sprintf('%d B', $bytes);
        }

        $units = ['B', 'KB', 'MB', 'GB'];
        $bytes = max($bytes, 0);
        $pow = $bytes > 0 ? floor(log($bytes, 1024)) : 0;
        $pow = min($pow, \count($units) - 1);

        $bytes /= (1024 ** $pow);

        return \sprintf('%.1f %s', $bytes, $units[(int) $pow]);
    }
}
