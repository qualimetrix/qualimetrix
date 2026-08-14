<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console;

use Psr\Log\LoggerInterface;
use Qualimetrix\Analysis\Configuration\Contract\TransitionalResolvedConfiguration;
use Qualimetrix\Analysis\Configuration\Contract\TransitionalRuntimeConfiguration;
use Qualimetrix\Analysis\Configuration\Pipeline\Stage\DefaultsStage;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Configuration\ComputedMetricConfiguratorInterface;
use Qualimetrix\Analysis\Evidence\Coupling\Contract\Configuration\CouplingConfiguratorInterface;
use Qualimetrix\Analysis\Policy\Architecture\Contract\ArchitecturePolicyConfiguratorInterface;
use Qualimetrix\Core\Profiler\ProfilerHolder;
use Qualimetrix\Core\Progress\NullProgressReporter;
use Qualimetrix\Infrastructure\Console\Progress\ConsoleProgressBar;
use Qualimetrix\Infrastructure\Console\Progress\ProgressReporterHolder;
use Qualimetrix\Infrastructure\Profiler\Profiler;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Configures runtime services (logger, progress reporter, profiler, rule options)
 * based on resolved configuration and CLI input.
 *
 * @qmx-threshold code-smell.constructor-overinjection warning=9 — The explicit runtime composition keeps three capability configurators independent instead of hiding them behind a generic registry.
 * @qmx-threshold code-smell.long-parameter-list warning=9 — Constructor arguments are explicit runtime collaborators; a parameter bag would obscure their lifecycle.
 */
final class RuntimeConfigurator
{
    public function __construct(
        private readonly RuntimeLoggerConfigurator $runtimeLoggerConfigurator,
        private readonly ProgressReporterHolder $progressReporterHolder,
        private readonly ProfilerHolder $profilerHolder,
        private readonly AnalysisRuntimeConfigurator $analysisRuntimeConfigurator,
        private readonly ArchitecturePolicyConfiguratorInterface $architecturePolicyConfigurator,
        private readonly ComputedMetricConfiguratorInterface $computedMetricConfigurator,
        private readonly CouplingConfiguratorInterface $couplingConfigurator,
        private readonly DiagnosticOutput $diagnosticOutput,
    ) {}

    /**
     * Configures all runtime services from resolved configuration and CLI input.
     */
    public function configure(
        TransitionalResolvedConfiguration $resolved,
        InputInterface $input,
        OutputInterface $output,
    ): void {
        $this->analysisRuntimeConfigurator->resetRunState();

        $logger = $this->runtimeLoggerConfigurator->configure($input, $output);

        $this->configureArchitecturePolicy($resolved, $logger);
        $this->computedMetricConfigurator->configure($resolved->document);
        $this->couplingConfigurator->configure($resolved->document);

        $this->configureMemoryLimit($resolved->runtime, $output);
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
    private function configureMemoryLimit(TransitionalRuntimeConfiguration $config, OutputInterface $output): void
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

    /** Configures Architecture only after the user-facing logger is ready. */
    private function configureArchitecturePolicy(
        TransitionalResolvedConfiguration $resolved,
        LoggerInterface $logger,
    ): void {
        foreach ($this->architecturePolicyConfigurator->configure($resolved->document) as $warning) {
            $logger->warning($warning->message, $warning->context);
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
