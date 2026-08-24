<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console;

use Qualimetrix\Analysis\Configuration\ConfigSchema;
use Qualimetrix\Analysis\Configuration\Contract\ConfigurationDocument;
use Qualimetrix\Analysis\Finding\Contract\Configuration\FindingConfiguration;
use Qualimetrix\Infrastructure\Cache\CacheFactory;
use Qualimetrix\Infrastructure\Cache\Contract\CacheConfiguration;
use Qualimetrix\Infrastructure\Console\Progress\ConsoleProgressBar;
use Qualimetrix\Infrastructure\Console\Progress\SwitchableProgressReporter;
use Qualimetrix\Infrastructure\Parallel\Contract\ParallelConfiguration;
use Qualimetrix\Infrastructure\Parallel\Contract\ParallelConfigurationStoreInterface;
use Qualimetrix\Infrastructure\Profiler\Contract\ProfileSessionControlInterface;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Configures runtime services (logger, progress reporter, profiler, rule options)
 * based on resolved configuration and CLI input.
 */
final class RuntimeConfigurator
{
    public function __construct(
        private readonly RuntimeLoggerConfigurator $runtimeLoggerConfigurator,
        private readonly SwitchableProgressReporter $progressReporter,
        private readonly ProfileSessionControlInterface $profileSession,
        private readonly AnalysisRuntimeConfigurator $analysisRuntimeConfigurator,
        private readonly CacheFactory $cacheFactory,
        private readonly ParallelConfigurationStoreInterface $parallelConfigurationStore,
        private readonly RuntimeLimitsController $runtimeLimitsController,
    ) {}

    /** Resets every mutable per-run seam before configuration resolution starts. */
    public function resetRunState(): void
    {
        $this->cacheFactory->resetConfiguration();
        $this->parallelConfigurationStore->reset();
        $this->analysisRuntimeConfigurator->resetRunState();
        $this->profileSession->disable();
        $this->progressReporter->reset();
        $this->runtimeLimitsController->reset();
    }

    /**
     * Configures all runtime services from resolved configuration and CLI input.
     */
    public function configure(
        ConfigurationDocument $document,
        FindingConfiguration $findingConfiguration,
        CacheConfiguration $cacheConfiguration,
        ParallelConfiguration $parallelConfiguration,
        InputInterface $input,
        OutputInterface $output,
    ): void {
        // Pure preflight: no store or external-effect mutation is allowed
        // until every owner has accepted its immutable value.
        $architecturePolicy = $this->analysisRuntimeConfigurator->resolveArchitecturePolicy($document);
        $computedMetrics = $this->analysisRuntimeConfigurator->resolveComputedMetrics($document);
        $frameworkNamespaces = $this->analysisRuntimeConfigurator->resolveCoupling($document);
        $lcomConfiguration = $this->analysisRuntimeConfigurator->resolveLcom($findingConfiguration);
        $runtimeLimits = $this->resolveRuntimeLimits($document);
        $capture = $input->hasOption('show-suppressed') && $input->getOption('show-suppressed') === true;
        $channels = $this->analysisRuntimeConfigurator->resolveRuleChannels(
            $input,
            $findingConfiguration,
            $computedMetrics,
        );

        // Built-in stores commit only after complete preflight. An unexpected
        // custom-store failure is fail-closed, but is not claimed to roll back.
        $this->cacheFactory->replaceConfiguration($cacheConfiguration);
        $this->parallelConfigurationStore->replace($parallelConfiguration);
        $this->analysisRuntimeConfigurator->replace(
            $findingConfiguration,
            $lcomConfiguration,
            $architecturePolicy,
            $computedMetrics,
            $frameworkNamespaces,
            $channels,
        );
        if ($capture) {
            $this->analysisRuntimeConfigurator->captureExcludedFindings();
        }

        // These are fallible process/output effects. Failure aborts before
        // analysis; committed stores are reset at the next invocation entry.
        $this->runtimeLimitsController->apply($runtimeLimits);
        $logger = $this->runtimeLoggerConfigurator->configure($input, $output);
        foreach ($architecturePolicy->warnings() as $warning) {
            $logger->warning($warning->message, $warning->context);
        }
        $this->configureProgressReporter($input, $output);
        $this->configureProfiler($input);
    }

    public function clearCacheIfRequested(InputInterface $input): bool
    {
        if (!$input->hasOption('clear-cache') || $input->getOption('clear-cache') !== true) {
            return false;
        }

        $this->cacheFactory->create()->clear();

        return true;
    }

    /**
     * Applies PHP memory limit from configuration.
     *
     * The default (512M) is set in DefaultsStage and can be overridden
     * via qmx.yaml or --memory-limit CLI option.
     */
    private function resolveRuntimeLimits(ConfigurationDocument $document): RuntimeLimits
    {
        $value = null;
        foreach ($document->contributions(ConfigSchema::MEMORY_LIMIT) as $candidate) {
            if (\is_string($candidate)) {
                $value = $candidate;
            }
        }

        return new RuntimeLimits($value);
    }

    /**
     * Configures progress reporter based on CLI options.
     *
     * Selects the per-run reporter on the stable console-owned switch so the
     * analysis pipeline can report progress without owning adapter state.
     */
    private function configureProgressReporter(InputInterface $input, OutputInterface $output): void
    {
        // Disable for non-TTY (CI, pipes)
        if (!$output->isDecorated()) {
            $this->progressReporter->reset();

            return;
        }

        // Explicit disable
        if ($input->hasOption('no-progress') && $input->getOption('no-progress') === true) {
            $this->progressReporter->reset();

            return;
        }

        // Disable for quiet mode
        if ($output->getVerbosity() === OutputInterface::VERBOSITY_QUIET) {
            $this->progressReporter->reset();

            return;
        }

        // Use console progress bar
        $this->progressReporter->enable(new ConsoleProgressBar($output));
    }

    /**
     * Configures profiler based on CLI options.
     */
    private function configureProfiler(InputInterface $input): void
    {
        if (!$input->hasOption('profile')) {
            return;
        }

        $profileOption = $input->getOption('profile');
        if ($profileOption === false) {
            return;
        }

        // Enable profiler if --profile or --profile=file was provided
        $this->profileSession->enable();
    }

}
