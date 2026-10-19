<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console\RunSetup\Finding;

use Qualimetrix\Analysis\Finding\Contract\Configuration\FindingConfiguration;

use Qualimetrix\Infrastructure\Console\RunSetup\Execution\ResolvedRunConfiguration;
use Qualimetrix\Reporting\Contract\OutputFormat;
use Qualimetrix\Reporting\FindingProjection\Contract\ConfiguredFindingExclusions;

/** The accepted run, exclusions, reporting format and Finding configuration from one document. */
final readonly class CheckResolvedConfiguration
{
    public function __construct(
        public ResolvedRunConfiguration $run,
        public ConfiguredFindingExclusions $findingExclusions,
        public OutputFormat $outputFormat,
        public FindingConfiguration $findingConfiguration,
    ) {}
}
