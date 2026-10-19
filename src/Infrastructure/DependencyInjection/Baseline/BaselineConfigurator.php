<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\DependencyInjection\Baseline;

use Qualimetrix\Analysis\Policy\Baseline\EntryBinding\UnusedEntryAudit;

use Qualimetrix\Infrastructure\DependencyInjection\Configurator\ContainerConfiguratorInterface;
use Qualimetrix\Infrastructure\DependencyInjection\Registration\EvidenceRegistration;
use Symfony\Component\Config\FileLocator;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;

final readonly class BaselineConfigurator implements ContainerConfiguratorInterface
{
    public function __construct(private string $srcDir) {}

    public function configure(ContainerBuilder $container): void
    {
        $this->registerBaseline($container);
        $loader = EvidenceRegistration::loader($container, $this->srcDir);
        $loader->registerClasses(
            EvidenceRegistration::rules(),
            'Qualimetrix\\Analysis\\Policy\\Baseline\\EntryBinding\\',
            $this->srcDir . '/Analysis/Policy/Baseline/EntryBinding/*Rule.php',
        );
        $container->register(UnusedEntryAudit::class)->setAutowired(true);
    }

    private function registerBaseline(ContainerBuilder $container): void
    {
        $loader = new PhpFileLoader($container, new FileLocator($this->srcDir));

        // Auto-register all baseline services from src/Analysis/Policy/Baseline/*
        // Excludes: value objects, enums, exceptions and pure static helpers —
        // data and functions, not services.
        // A value object with required constructor arguments cannot be
        // autowired, so leaving one in would fail container compilation
        // rather than merely registering something unused.
        $prototype = (new Definition())->setAutoconfigured(true)->setAutowired(true);
        $loader->registerClasses(
            $prototype,
            'Qualimetrix\\Analysis\\Policy\\Baseline\\',
            $this->srcDir . '/Analysis/Policy/Baseline/{Capture,Ceiling,ChannelRename,Contract,DocumentAccess,DocumentReading,DocumentWriting,EntryBinding,EntryMaintenance,Explanation,RunCoverage}/*',
            [
                $this->srcDir . '/Analysis/Policy/Baseline/DocumentAccess/BaselineConflictException.php',
                $this->srcDir . '/Analysis/Policy/Baseline/DocumentReading/BaselineEntryRejection.php',
                $this->srcDir . '/Analysis/Policy/Baseline/Capture/BaselineCapture.php',
                $this->srcDir . '/Analysis/Policy/Baseline/Capture/UncapturedGroup.php',
                $this->srcDir . '/Analysis/Policy/Baseline/DocumentWriting/BaselineDocumentLayout.php',
                $this->srcDir . '/Analysis/Policy/Baseline/DocumentWriting/BaselineEntryOrder.php',
                $this->srcDir . '/Analysis/Policy/Baseline/ChannelRename/BaselineEntryPayload.php',
                $this->srcDir . '/Analysis/Policy/Baseline/DocumentReading/BaselineFormatVersion.php',
                $this->srcDir . '/Analysis/Policy/Baseline/DocumentReading/BaselineFileShape.php',
                $this->srcDir . '/Analysis/Policy/Baseline/Explanation/BoundaryThresholdSources.php',
                $this->srcDir . '/Analysis/Policy/Baseline/Explanation/BoundaryRunFacts.php',
                $this->srcDir . '/Analysis/Policy/Baseline/DocumentReading/BaselineExclusionShape.php',
                $this->srcDir . '/Analysis/Policy/Baseline/DocumentReading/CanonicalEnvelope.php',
                $this->srcDir . '/Analysis/Policy/Baseline/DocumentReading/BaselineEntryShape.php',
                $this->srcDir . '/Analysis/Policy/Baseline/Explanation/CurrentAbsentMeasurement.php',
                $this->srcDir . '/Analysis/Policy/Baseline/ChannelRename/ChannelRenameMap.php',
                $this->srcDir . '/Analysis/Policy/Baseline/ChannelRename/ChannelRenameReport.php',
                $this->srcDir . '/Analysis/Policy/Baseline/ChannelRename/ChannelRenameRefusal.php',
                $this->srcDir . '/Analysis/Policy/Baseline/Explanation/ExplainedSubject.php',
                $this->srcDir . '/Analysis/Policy/Baseline/Ceiling/**',
                $this->srcDir . '/Analysis/Policy/Baseline/Contract/**',
                $this->srcDir . '/Analysis/Policy/Baseline/EntryBinding/**',
            ],
        );
    }
}
