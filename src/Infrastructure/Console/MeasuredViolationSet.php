<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console;

use Qualimetrix\Analysis\Run\Contract\Discovery\FileDiscoveryInterface;
use Qualimetrix\Analysis\Run\Contract\Pipeline\AnalysisPipelineInterface;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Reporting\FindingProjection\FindingProjectionOptions;
use Qualimetrix\Reporting\FindingProjection\FindingProjector;

final readonly class MeasuredViolationSet
{
    public function __construct(
        private AnalysisPipelineInterface $analyzer,
        private FindingProjector $projector,
    ) {}

    /**
     * @param list<AbsolutePath> $paths
     *
     * @return list<\Qualimetrix\Analysis\Finding\Contract\Violation>
     */
    public function forPaths(array $paths, ?FileDiscoveryInterface $fileDiscovery = null, FindingProjectionOptions $options = new FindingProjectionOptions()): array
    {
        return $this->runForPaths($paths, $fileDiscovery, $options)->violations;
    }

    /** @param list<AbsolutePath> $paths */
    public function runForPaths(array $paths, ?FileDiscoveryInterface $fileDiscovery = null, FindingProjectionOptions $options = new FindingProjectionOptions()): MeasuredAnalysisRun
    {
        $result = $this->analyzer->analyze($paths, $fileDiscovery);
        $projection = $this->projector->project(
            $result->violations,
            $result->suppressions,
            $options,
        );
        return new MeasuredAnalysisRun($result, $projection->measuredViolations);
    }
}
