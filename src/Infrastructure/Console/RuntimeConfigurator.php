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
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\ConsoleSectionOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Output\StreamOutput;

/**
 * Configures runtime services (logger, progress reporter, profiler, rule options)
 * based on resolved configuration and CLI input.
 */
final class RuntimeConfigurator
{
    /**
     * The sections drawn over the error stream, owned here because
     * `ConsoleSectionOutput` takes the list by reference and keeps the
     * reference: whoever holds the array is what makes several sections over
     * one stream redraw around each other. The error stream has no owner that
     * already holds one — `ConsoleOutput` keeps its list for stdout only.
     *
     * @var array<int, ConsoleSectionOutput>
     */
    private array $progressSections = [];

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
        $this->progressSections = [];
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
     * Resolves the effective `--format`/`format:` value without a second
     * service dependency: {@see \Qualimetrix\Reporting\Configuration\OutputFormatResolver}
     * reads the identical contribution list, and duplicating the two-line
     * read here is cheaper than wiring a Reporting contract into this class
     * for one string.
     *
     * The per-rule exclusion ledger's `--show-suppressed`-gated capture
     * (decision (д), Ш6) must also arm for `--format=suppressed` /
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
     * Configures progress reporter based on CLI options.
     *
     * Selects the per-run reporter on the stable console-owned switch so the
     * analysis pipeline can report progress without owning adapter state.
     *
     * **Every question below is asked of the error stream, because that is
     * where the bar is drawn.** Asking stdout instead would answer for the
     * wrong file: `qmx check --format=json > report.json` on a terminal has an
     * undecorated stdout and a live stderr, and that run is exactly the one
     * this arrangement exists for.
     */
    private function configureProgressReporter(InputInterface $input, OutputInterface $output): void
    {
        $error = $this->progressStream($output);

        // No distinguishable error stream: a buffer, a NullOutput. Progress is
        // not possible, so nothing is enabled.
        if ($error === null) {
            $this->progressReporter->reset();

            return;
        }

        // Disable for non-TTY (CI, pipes, a redirected error stream)
        if (!$error->isDecorated()) {
            $this->progressReporter->reset();

            return;
        }

        // Explicit disable
        if ($input->hasOption('no-progress') && $input->getOption('no-progress') === true) {
            $this->progressReporter->reset();

            return;
        }

        // Disable for quiet mode
        if ($error->getVerbosity() === OutputInterface::VERBOSITY_QUIET) {
            $this->progressReporter->reset();

            return;
        }

        // Use console progress bar
        $this->progressReporter->enable(new ConsoleProgressBar($this->progressSection($error)));
    }

    /**
     * The error stream this run may draw progress on, or null when it has none
     * of its own — a buffered output, a `NullOutput`, anything that is not a
     * console.
     */
    private function progressStream(OutputInterface $output): ?StreamOutput
    {
        if (!$output instanceof ConsoleOutputInterface) {
            return null;
        }

        $error = $output->getErrorOutput();

        return $error instanceof StreamOutput ? $error : null;
    }

    /**
     * A redrawable section over the error stream.
     *
     * Built by hand rather than asked for: `getErrorOutput()` hands back a
     * plain `StreamOutput`, which has no `section()`. Verbosity, decoration and
     * the formatter come from that stream for the same reason the gates above
     * are asked of it — they describe the file being written to.
     *
     * Diagnostics do not pass through this section:
     * {@see \Qualimetrix\Infrastructure\Console\DiagnosticOutput} and the
     * logger factory write to `getErrorOutput()` directly. A warning emitted
     * mid-run can therefore tear the bar's frame. That is the accepted price of
     * not rewiring four unrelated seams here, not an oversight.
     */
    private function progressSection(StreamOutput $error): ConsoleSectionOutput
    {
        return new ConsoleSectionOutput(
            $error->getStream(),
            $this->progressSections,
            $error->getVerbosity(),
            $error->isDecorated(),
            $error->getFormatter(),
        );
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
