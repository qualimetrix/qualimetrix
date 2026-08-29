<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console;

use Qualimetrix\Analysis\Run\Contract\Configuration\RunConfiguration;
use Qualimetrix\Analysis\Run\Contract\Discovery\FileDiscoveryFactoryInterface;
use Qualimetrix\Analysis\Run\Contract\Discovery\FileDiscoveryInterface;
use Qualimetrix\Analysis\Run\Contract\Pipeline\AnalysisPipelineInterface;
use Qualimetrix\Reporting\FindingProjection\FindingProjectionOptions;
use Qualimetrix\Reporting\FindingProjection\FindingProjector;

final readonly class MeasuredFindingSet
{
    public function __construct(
        private AnalysisPipelineInterface $analyzer,
        private FindingProjector $projector,
        private FileDiscoveryFactoryInterface $fileDiscoveryFactory,
    ) {}

    /**
     * @return list<\Qualimetrix\Analysis\Finding\Contract\Finding>
     */
    public function forRun(RunConfiguration $configuration, ?FileDiscoveryInterface $fileDiscovery = null, FindingProjectionOptions $options = new FindingProjectionOptions()): array
    {
        return $this->run($configuration, $fileDiscovery, $options)->findings;
    }

    public function run(RunConfiguration $configuration, ?FileDiscoveryInterface $fileDiscovery = null, FindingProjectionOptions $options = new FindingProjectionOptions()): MeasuredAnalysisRun
    {
        $result = $this->analyzer->analyze(
            $configuration,
            $fileDiscovery ?? $this->fileDiscoveryFactory->create($configuration->pathExcludes),
        );
        $projection = $this->projector->project(
            $result->findings,
            $result->suppressions,
            $options,
        );
        return new MeasuredAnalysisRun($result, $projection->measuredFindings);
    }
}
