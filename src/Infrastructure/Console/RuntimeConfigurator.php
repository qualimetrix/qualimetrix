<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console;

use Qualimetrix\Analysis\Configuration\ConfigSchema;
use Qualimetrix\Analysis\Configuration\Contract\ConfigurationDocument;
use Qualimetrix\Analysis\Finding\Contract\Configuration\FindingConfiguration;
use Qualimetrix\Analysis\Run\Contract\Configuration\RunConfiguration;
use Qualimetrix\Infrastructure\Cache\CacheFactory;
use Qualimetrix\Infrastructure\Cache\Contract\CacheConfiguration;
use Qualimetrix\Infrastructure\Composer\Contract\AnalysedInstallAnchorInterface;
use Qualimetrix\Infrastructure\Console\Progress\ProgressConfigurator;
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
    /**
     * @qmx-threshold code-smell.constructor-overinjection warning=9 error=10 -- This class composes a run's unrelated per-run concerns (logging, progress, profiling, cache, parallelism, limits) and the eighth is the autoload anchor DIT's ancestor walk needs before the pipeline starts. Splitting the composition is its own subject, not this metric's campaign; the ceiling is one slot: a ninth parameter reports again.
     * @qmx-threshold code-smell.long-parameter-list warning=9 error=10 -- Same constructor, same reason: the list is the composition, and a ninth parameter reports again.
     */
    public function __construct(
        private readonly RuntimeLoggerConfigurator $runtimeLoggerConfigurator,
        private readonly ProgressConfigurator $progressConfigurator,
        private readonly ProfileSessionControlInterface $profileSession,
        private readonly AnalysisRuntimeConfigurator $analysisRuntimeConfigurator,
        private readonly CacheFactory $cacheFactory,
        private readonly ParallelConfigurationStoreInterface $parallelConfigurationStore,
        private readonly RuntimeLimitsController $runtimeLimitsController,
        private readonly AnalysedInstallAnchorInterface $analysedAutoloadMap,
    ) {}

    /** Resets every mutable per-run seam before configuration resolution starts. */
    public function resetRunState(): void
    {
        $this->cacheFactory->resetConfiguration();
        $this->parallelConfigurationStore->reset();
        $this->analysisRuntimeConfigurator->resetRunState();
        $this->profileSession->disable();
        $this->progressConfigurator->reset();
        $this->runtimeLimitsController->reset();
    }

    /**
     * Configures all runtime services from resolved configuration and CLI input.
     */
    public function configure(
        ConfigurationDocument $document,
        RunConfiguration $runConfiguration,
        FindingConfiguration $findingConfiguration,
        CacheConfiguration $cacheConfiguration,
        ParallelConfiguration $parallelConfiguration,
        InputInterface $input,
        OutputInterface $output,
    ): void {
        // Every command that runs the pipeline passes through here, which is
        // why the anchor lives in this call rather than at one call site: DIT's
        // ancestor walk silently reports "no install" for any run that forgot
        // to aim it, and `baseline:generate` forgetting it means the baseline
        // records a magnitude `check` never produces.
        $this->analysedAutoloadMap->pointAt(
            (string) $runConfiguration->projectRoot,
            array_map(static fn(object $path): string => (string) $path, $runConfiguration->paths),
        );

        // Pure preflight: no store or external-effect mutation is allowed
        // until every owner has accepted its immutable value.
        $architecturePolicy = $this->analysisRuntimeConfigurator->resolveArchitecturePolicy($document);
        $computedMetrics = $this->analysisRuntimeConfigurator->resolveComputedMetrics($document);
        $frameworkNamespaces = $this->analysisRuntimeConfigurator->resolveCoupling($document);
        $lcomConfiguration = $this->analysisRuntimeConfigurator->resolveLcom($findingConfiguration);
        $runtimeLimits = $this->resolveRuntimeLimits($document);
        $capture = ($input->hasOption('show-suppressed') && $input->getOption('show-suppressed') === true)
            || $this->resolveFormat($document) === 'suppressed';
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
        $this->progressConfigurator->configure($input, $output);
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
     * Resolves the effective `--format`/`format:` value without a second
     * service dependency: {@see \Qualimetrix\Reporting\Configuration\OutputFormatResolver}
     * reads the identical contribution list, and duplicating the two-line
     * read here is cheaper than wiring a Reporting contract into this class
     * for one string.
     *
     * The per-rule exclusion ledger's `--show-suppressed`-gated capture must
     * also arm for `--format=suppressed` /
     * `format: suppressed`: the format's payload reads
     * {@see \Qualimetrix\Analysis\Finding\Contract\RuleExclusionStats::$excludedFindings},
     * and that field is opt-in precisely because most runs never display it.
     * Without this, selecting the format through `qmx.yaml` alone —
     * {@see \Qualimetrix\Reporting\Configuration\OutputFormatResolver} is fed
     * by both `qmx.yaml` and the CLI — would silently report an empty ledger
     * half.
     */
    private function resolveFormat(ConfigurationDocument $document): ?string
    {
        $value = null;
        foreach ($document->contributions(ConfigSchema::FORMAT) as $contribution) {
            if (\is_string($contribution)) {
                $value = $contribution;
            }
        }

        return $value;
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
