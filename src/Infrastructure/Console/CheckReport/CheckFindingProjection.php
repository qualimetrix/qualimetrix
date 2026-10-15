<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console\CheckReport;

use Qualimetrix\Analysis\Run\Contract\Configuration\RunConfiguration;
use Qualimetrix\Analysis\Run\Contract\Pipeline\AnalysisResult;
use Qualimetrix\Infrastructure\Console\CheckScope\ResolvedCheckScope;
use Qualimetrix\Reporting\FindingProjection\FindingProjectionOptions;
use Qualimetrix\Reporting\FindingProjection\FindingProjectionResult;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final readonly class CheckFindingProjection
{
    public function __construct(private FindingProjection $projection, private FindingDiagnostics $diagnostics) {}

    public function project(AnalysisResult $result, InputInterface $input, OutputInterface $output, ResolvedCheckScope $scope, FindingProjectionOptions $options, RunConfiguration $configuration): FindingProjectionResult
    {
        $projection = $this->projection->project($result, $options, $configuration);
        $this->diagnostics->report($result, $projection, $input, $output, $scope);
        return $projection;
    }
}
