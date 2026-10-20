<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console\RunSetup;

use Qualimetrix\Analysis\Configuration\ConfigSchema;
use Qualimetrix\Analysis\Configuration\Contract\ConfigurationDocument;

use Qualimetrix\Analysis\Finding\Contract\Configuration\FindingConfiguration;

use Qualimetrix\Infrastructure\Cache\CacheFactory;
use Qualimetrix\Infrastructure\Console\CacheLifecycle\CacheRunLifecycle;
use Qualimetrix\Infrastructure\Console\Invocation\Configuration\Diagnostics\AnalysisPreflightProfile;
use Qualimetrix\Infrastructure\Console\RunSetup\CapabilityState\AnalysisRuntimeConfigurator;
use Qualimetrix\Infrastructure\Console\RunSetup\Execution\ResolvedRunConfiguration;
use Qualimetrix\Infrastructure\Console\RuntimePolicy\RuntimeEffects;
use Qualimetrix\Infrastructure\Parallel\Contract\ParallelConfigurationStoreInterface;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final readonly class RuntimeConfigurator
{
    public function __construct(
        private readonly AnalysisRuntimeConfigurator $analysisRuntimeConfigurator,
        private readonly CacheFactory $cacheFactory,
        private readonly ParallelConfigurationStoreInterface $parallelConfigurationStore,
        private readonly ProjectSourceConfigurator $projectSourceConfigurator,
        private readonly RuntimeEffects $effects,
        private readonly CacheRunLifecycle $cacheLifecycle,
    ) {}

    public function resetRunState(): void
    {
        $this->cacheLifecycle->reset();
        $this->parallelConfigurationStore->reset();
        $this->analysisRuntimeConfigurator->resetRunState();
        $this->effects->reset();
    }

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
        $runtimeLimits = $this->effects->prepare($document);
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
        $this->effects->apply($runtimeLimits, $prepared?->architecturePolicy->warnings() ?? [], $input, $output);
    }

    private function captureExcludedFindings(ConfigurationDocument $document, InputInterface $input, AnalysisPreflightProfile $profile): void
    {
        if (($input->hasOption('show-suppressed') && $input->getOption('show-suppressed') === true)
            || ($profile->requiresReportingFormat && $this->resolveFormat($document) === 'suppressed')) {
            $this->analysisRuntimeConfigurator->captureExcludedFindings();
        }
    }

    private function resolveFormat(ConfigurationDocument $document): ?string
    {
        $format = $document->resolved()->get(ConfigSchema::FORMAT)?->plain();

        return \is_string($format) ? $format : null;
    }
}
