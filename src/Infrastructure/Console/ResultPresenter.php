<?php

declare(strict_types=1);

namespace AiMessDetector\Infrastructure\Console;

use AiMessDetector\Analysis\Pipeline\AnalysisResult;
use AiMessDetector\Baseline\BaselineGenerator;
use AiMessDetector\Baseline\BaselineWriter;
use AiMessDetector\Configuration\AnalysisConfiguration;
use AiMessDetector\Core\Profiler\ProfilerHolder;
use AiMessDetector\Core\Violation\Severity;
use AiMessDetector\Core\Violation\Violation;
use AiMessDetector\Reporting\Formatter\FormatterInterface;
use AiMessDetector\Reporting\Formatter\FormatterRegistryInterface;
use AiMessDetector\Reporting\FormatterContext;
use AiMessDetector\Reporting\GroupBy;
use AiMessDetector\Reporting\ReportBuilder;
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
    ): int {
        /** @var string $format */
        $format = $input->getOption('format') ?? AnalysisConfiguration::DEFAULT_FORMAT;
        $formatter = $this->formatterRegistry->get($format);
        $context = $this->buildFormatterContext($input, $output, $formatter);

        // Build and output report with filtered violations
        $report = ReportBuilder::create()
            ->addViolations($violations)
            ->filesAnalyzed($analysisResult->filesAnalyzed)
            ->filesSkipped($analysisResult->filesSkipped)
            ->duration($analysisResult->duration)
            ->metrics($analysisResult->metrics)
            ->build();
        OutputHelper::write($output, $formatter->format($report, $context));

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
        file_put_contents($tmpFile, $profileData);
        rename($tmpFile, $profileOption);

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

    private function buildFormatterContext(
        InputInterface $input,
        OutputInterface $output,
        FormatterInterface $formatter,
    ): FormatterContext {
        // Resolve group-by: explicit CLI option or formatter default
        /** @var string|null $groupByValue */
        $groupByValue = $input->getOption('group-by');
        try {
            $groupBy = $groupByValue !== null
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

        return new FormatterContext(
            useColor: $output->isDecorated(),
            groupBy: $groupBy,
            options: $options,
        );
    }

    /**
     * Determines exit code based on violation severity.
     *
     * @param list<Violation> $violations
     */
    private function determineExitCode(array $violations): int
    {
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
            return 2;
        }

        if ($hasWarnings) {
            return 1;
        }

        return 0;
    }

    /**
     * Formats profiling summary for console output.
     *
     * @param array<string, array{total: float, count: int, avg: float, memory: int}> $summary
     */
    private function formatProfileSummary(array $summary): string
    {
        if ($summary === []) {
            return '<comment>No profiling data available</comment>';
        }

        // Calculate total time
        $totalTime = 0.0;
        foreach ($summary as $stat) {
            $totalTime += $stat['total'];
        }

        // Sort by total time descending
        uasort($summary, fn($a, $b) => $b['total'] <=> $a['total']);

        $lines = ['<comment>Profile summary:</comment>'];

        foreach ($summary as $name => $stat) {
            $percentage = $totalTime > 0 ? ($stat['total'] / $totalTime) * 100 : 0;
            $memory = $this->formatBytes($stat['memory']);

            $lines[] = \sprintf(
                '  <info>%s</info>: %.3fs (%3.0f%%) | %s | %dx',
                str_pad($name, 15),
                $stat['total'] / 1000, // ms to s
                $percentage,
                str_pad($memory, 8),
                $stat['count'],
            );
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
