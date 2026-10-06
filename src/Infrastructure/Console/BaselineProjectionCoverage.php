<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console;

use Qualimetrix\Analysis\Policy\Baseline\Contract\RecordedExclusions;
use Qualimetrix\Analysis\Policy\Baseline\Contract\RunCoverage;
use Qualimetrix\Analysis\Policy\Baseline\RunRuleCoverage;
use Qualimetrix\Analysis\Policy\Baseline\RunScope;
use Qualimetrix\Analysis\ProjectManifest\Contract\ComposerManifestReaderInterface;
use Qualimetrix\Analysis\Run\Contract\Configuration\RunConfiguration;
use Qualimetrix\Analysis\Run\Contract\Discovery\ProjectTreeQueryInterface;
use Qualimetrix\Analysis\Run\Contract\Pipeline\AnalysisResult;
use Qualimetrix\Reporting\FindingProjection\FindingProjectionOptions;

/** Carries the completed run's coverage into baseline finding projection. */
final readonly class BaselineProjectionCoverage
{
    public function __construct(
        private ComposerManifestReaderInterface $composerReader,
        private ProjectTreeQueryInterface $projectTree,
        private RunRuleCoverage $ruleCoverage,
    ) {}

    public function withRunCoverage(
        FindingProjectionOptions $options,
        AnalysisResult $result,
        RunConfiguration $configuration,
    ): FindingProjectionOptions {
        if ($options->baselineDocument === null) {
            return $options;
        }

        return $options->withRunCoverage(new RunCoverage(
            RunScope::record($configuration->paths, $configuration->projectRoot),
            $result->measured->coverage,
            RecordedExclusions::fromRunConfiguration($configuration),
            $configuration->projectScope->universe,
            $this->composerReader->read($configuration->projectRoot)->psr4Roots(),
            $this->projectTree,
            $result->measured->subjectCoverage,
        ), $this->ruleCoverage);
    }
}
