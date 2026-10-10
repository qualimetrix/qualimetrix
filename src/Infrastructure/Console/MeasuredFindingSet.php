<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console;

use LogicException;

use Qualimetrix\Analysis\Run\Contract\Configuration\RunConfiguration;
use Qualimetrix\Analysis\Run\Contract\Pipeline\AnalysisPipelineInterface;
use Qualimetrix\Reporting\FindingProjection\FindingProjectionOptions;
use Qualimetrix\Reporting\FindingProjection\FindingProjector;

final readonly class MeasuredFindingSet
{
    public function __construct(
        private AnalysisPipelineInterface $analyzer,
        private FindingProjector $projector,
    ) {}

    /**
     * @return list<\Qualimetrix\Analysis\Finding\Contract\Finding>
     */
    public function forRun(RunConfiguration $configuration, FindingProjectionOptions $options = new FindingProjectionOptions()): array
    {
        return $this->run($configuration, $options)->findings;
    }

    public function run(RunConfiguration $configuration, FindingProjectionOptions $options = new FindingProjectionOptions()): MeasuredAnalysisRun
    {
        $result = $this->analyzer->analyze($configuration);
        $projection = $this->projector->project(
            $result->findings(),
            $result->directives->suppressions,
            $options,
            $result->populationPublication ?? throw new LogicException('Measured finding projection requires captured channel publication'),
        );
        return new MeasuredAnalysisRun($result, $projection->measuredFindings);
    }
}
