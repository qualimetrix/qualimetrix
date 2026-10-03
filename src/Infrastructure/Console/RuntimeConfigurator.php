<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console;

use Qualimetrix\Analysis\Configuration\ConfigSchema;
use Qualimetrix\Analysis\Configuration\Contract\ConfigurationDocument;
use Qualimetrix\Analysis\Finding\Contract\Configuration\FindingConfiguration;
use Qualimetrix\Infrastructure\Cache\CacheClearOutcome;
use Qualimetrix\Infrastructure\Cache\CacheFactory;
use Qualimetrix\Infrastructure\Console\Progress\ProgressConfigurator;
use Qualimetrix\Infrastructure\Console\Refusal\EnvironmentRefusal;
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
        private readonly ProjectSourceConfigurator $projectSourceConfigurator,
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
        $this->runtimeLoggerConfigurator->reset();
    }

    /**
     * Configures all runtime services from resolved configuration and CLI input.
     */
    public function configure(
        ConfigurationDocument $document,
        ResolvedRunConfiguration $run,
        ?FindingConfiguration $findingConfiguration,
        InputInterface $input,
        OutputInterface $output,
        ?AnalysisPreflightProfile $profile = null,
    ): void {
        $profile ??= AnalysisPreflightProfile::analysis();
        $this->projectSourceConfigurator->configure($run->runConfiguration->projectRoot, $run->runConfiguration->paths);

        // Pure preflight: no store or external-effect mutation is allowed
        // until every owner has accepted its immutable value.
        $runtimeLimits = $this->resolveRuntimeLimits($document);
        $prepared = $findingConfiguration === null ? null : $this->analysisRuntimeConfigurator->prepare($document, $findingConfiguration, $input);
        $frameworkNamespaces = $prepared !== null ? $prepared->frameworkNamespaces : $this->analysisRuntimeConfigurator->resolveCoupling($document);

        // Built-in stores commit only after complete preflight. An unexpected
        // custom-store failure is fail-closed, but is not claimed to roll back.
        $this->cacheFactory->replaceConfiguration($run->cacheConfiguration);
        $this->parallelConfigurationStore->replace($run->parallelConfiguration);
        if ($prepared !== null) {
            $this->analysisRuntimeConfigurator->replace($prepared);
            $this->captureExcludedFindings($document, $input, $profile);
        } else {
            $this->analysisRuntimeConfigurator->replaceCoupling($frameworkNamespaces);
        }

        // These are fallible process/output effects. Failure aborts before
        // analysis; committed stores are reset at the next invocation entry.
        $this->runtimeLimitsController->apply($runtimeLimits);
        $logger = $this->runtimeLoggerConfigurator->configure($input, $output);
        if ($run->cacheConfiguration->disabledBecause !== null) {
            $logger->warning($run->cacheConfiguration->disabledBecause);
        }
        if ($prepared !== null) {
            foreach ($prepared->architecturePolicy->warnings() as $warning) {
                $logger->warning($warning->message, $warning->context);
            }
        }
        $this->progressConfigurator->configure($input, $output);
        $this->configureProfiler($input);
    }

    private function captureExcludedFindings(ConfigurationDocument $document, InputInterface $input, AnalysisPreflightProfile $profile): void
    {
        if (($input->hasOption('show-suppressed') && $input->getOption('show-suppressed') === true)
            || ($profile->requiresReportingFormat && $this->resolveFormat($document) === 'suppressed')) {
            $this->analysisRuntimeConfigurator->captureExcludedFindings();
        }
    }

    public function clearCacheIfRequested(InputInterface $input): bool
    {
        if (!$input->hasOption('clear-cache') || $input->getOption('clear-cache') !== true) {
            return false;
        }

        $outcome = $this->cacheFactory->create()->clear();
        self::refuseIncompleteCacheClear($outcome);

        return true;
    }

    private static function refuseIncompleteCacheClear(CacheClearOutcome $outcome): void
    {
        if ($outcome->complete) {
            return;
        }

        throw EnvironmentRefusal::aboutFile(
            $outcome->directory,
            'clear cache in',
            \sprintf('%d cache entries remain: %s', $outcome->remaining, $outcome->reason ?? 'unknown reason'),
        );
    }

    /**
     * Applies PHP memory limit from configuration.
     *
     * No layer sets a default: with no `memory_limit` written anywhere, PHP's
     * own limit stands.
     */
    private function resolveRuntimeLimits(ConfigurationDocument $document): RuntimeLimits
    {
        return RuntimeLimits::fromResolvedValue($document->resolved()->get(ConfigSchema::MEMORY_LIMIT));
    }

    /**
     * Resolves the effective `--format`/`format:` value without a second
     * service dependency: {@see \Qualimetrix\Reporting\Configuration\OutputFormatResolver}
     * reads the same resolved value, and duplicating the two-line
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
        $format = $document->resolved()->get(ConfigSchema::FORMAT)?->plain();

        return \is_string($format) ? $format : null;
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
