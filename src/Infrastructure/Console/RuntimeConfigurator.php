<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console;

use Psr\Log\LogLevel;
use Qualimetrix\Configuration\AnalysisConfiguration;
use Qualimetrix\Configuration\Pipeline\ResolvedConfiguration;
use Qualimetrix\Core\Profiler\ProfilerHolder;
use Qualimetrix\Core\Progress\NullProgressReporter;
use Qualimetrix\Infrastructure\Console\Progress\ConsoleProgressBar;
use Qualimetrix\Infrastructure\Console\Progress\ProgressReporterHolder;
use Qualimetrix\Infrastructure\Logging\LoggerFactory;
use Qualimetrix\Infrastructure\Logging\LoggerHolder;
use Qualimetrix\Infrastructure\Profiler\Profiler;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Configures runtime services (logger, progress reporter, profiler, rule options)
 * based on resolved configuration and CLI input.
 */
final class RuntimeConfigurator
{
    public function __construct(
        private readonly LoggerFactory $loggerFactory,
        private readonly LoggerHolder $loggerHolder,
        private readonly ProgressReporterHolder $progressReporterHolder,
        private readonly ProfilerHolder $profilerHolder,
        private readonly AnalysisRuntimeConfigurator $analysisRuntimeConfigurator,
        private readonly DiagnosticOutput $diagnosticOutput = new DiagnosticOutput(),
    ) {}

    /**
     * Configures all runtime services from resolved configuration and CLI input.
     */
    public function configure(
        ResolvedConfiguration $resolved,
        InputInterface $input,
        OutputInterface $output,
    ): void {
        $this->analysisRuntimeConfigurator->resetRunState();

        $this->configureLogger($input, $output);

        // Drain warnings captured during configuration resolution (e.g. mutual-allow
        // detection in ArchitectureConfigurationFactory). These were buffered as
        // DeferredWarning records because the user-facing logger was not yet
        // configured at that point — replay them now that it is.
        $this->drainDeferredWarnings($resolved);

        $this->configureMemoryLimit($resolved->analysis, $output);
        $this->configureProgressReporter($input, $output);
        $this->configureProfiler($input);
        $this->analysisRuntimeConfigurator->configure($resolved, $input);
    }

    /**
     * Applies PHP memory limit from configuration.
     *
     * The default (512M) is set in DefaultsStage and can be overridden
     * via qmx.yaml or --memory-limit CLI option.
     */
    private function configureMemoryLimit(AnalysisConfiguration $config, OutputInterface $output): void
    {
        if ($config->memoryLimit === null) {
            return;
        }

        $result = ini_set('memory_limit', $config->memoryLimit);

        if ($result === false) {
            $this->diagnosticOutput->write($output, \sprintf(
                '<comment>Warning: failed to set memory_limit to %s. ini_set() may be disabled.</comment>',
                $config->memoryLimit,
            ));
        }
    }

    /**
     * Configures logger based on CLI options.
     *
     * Creates appropriate logger using LoggerFactory and sets it in LoggerHolder
     * so that all components (Analyzer, PhpFileParser) can use it.
     *
     * Defensive about option presence: commands other than `check` (e.g.
     * `debug:layer-assignment`) reuse this configurator to apply the YAML
     * `memory_limit` before parallel collection, but don't expose every
     * logging/profiling option. Missing options fall back to defaults.
     */
    private function configureLogger(InputInterface $input, OutputInterface $output): void
    {
        // Get log file path and level from CLI options
        $logFile = $input->hasOption('log-file') ? $input->getOption('log-file') : null;
        $logLevel = $input->hasOption('log-level') ? $input->getOption('log-level') : null;

        // Validate log file path
        if (!\is_string($logFile) && $logFile !== null) {
            $logFile = null;
        }

        // Validate log level
        if (!\is_string($logLevel)) {
            $logLevel = LogLevel::INFO;
        }

        // Normalize log level
        $logLevel = strtolower($logLevel);
        $validLevels = ['debug', 'info', 'warning', 'error'];
        if (!\in_array($logLevel, $validLevels, true)) {
            $logLevel = LogLevel::INFO;
        }

        // Create logger
        $logger = $this->loggerFactory->create($output, $logFile, $logLevel);

        // Set logger in holder so all components can use it
        $this->loggerHolder->setLogger($logger);
    }

    /**
     * Replays warnings captured during configuration resolution.
     *
     * Configuration resolution happens before {@see self::configureLogger()},
     * so the {@see LoggerHolder} still carries a NullLogger when the
     * architecture factory runs. To prevent its warnings (currently:
     * `mutual-allow` detection in the allow-list) from being dropped, the
     * factory buffers them in
     * {@see \Qualimetrix\Configuration\Pipeline\ResolvedConfiguration::$deferredWarnings};
     * this method drains the buffer through the now-configured logger.
     */
    private function drainDeferredWarnings(ResolvedConfiguration $resolved): void
    {
        if ($resolved->deferredWarnings === []) {
            return;
        }

        $logger = $this->loggerHolder->getLogger();
        foreach ($resolved->deferredWarnings as $warning) {
            $logger->log($warning->level, $warning->message, $warning->context);
        }
    }

    /**
     * Configures progress reporter based on CLI options.
     *
     * Creates appropriate progress reporter and sets it in ProgressReporterHolder
     * so that Analyzer can report progress during analysis.
     */
    private function configureProgressReporter(InputInterface $input, OutputInterface $output): void
    {
        // Disable for non-TTY (CI, pipes)
        if (!$output->isDecorated()) {
            $this->progressReporterHolder->setReporter(new NullProgressReporter());

            return;
        }

        // Explicit disable
        if ($input->hasOption('no-progress') && $input->getOption('no-progress') === true) {
            $this->progressReporterHolder->setReporter(new NullProgressReporter());

            return;
        }

        // Disable for quiet mode
        if ($output->getVerbosity() === OutputInterface::VERBOSITY_QUIET) {
            $this->progressReporterHolder->setReporter(new NullProgressReporter());

            return;
        }

        // Use console progress bar
        $this->progressReporterHolder->setReporter(new ConsoleProgressBar($output));
    }

    /**
     * Configures profiler based on CLI options.
     */
    private function configureProfiler(InputInterface $input): void
    {
        if (!$input->hasOption('profile')) {
            // Command does not expose `--profile`; profiler stays as NullProfiler.
            return;
        }

        $profileOption = $input->getOption('profile');

        // If --profile was not provided, profiler stays as NullProfiler (default)
        if ($profileOption === false) {
            return;
        }

        // Enable profiler if --profile or --profile=file was provided
        $this->profilerHolder->set(new Profiler()); // @phpstan-ignore staticMethod.dynamicCall
    }

}
