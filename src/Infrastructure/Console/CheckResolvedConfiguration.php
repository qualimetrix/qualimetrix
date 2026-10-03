<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console;

use Qualimetrix\Reporting\Contract\OutputFormat;
use Qualimetrix\Reporting\FindingProjection\Contract\ConfiguredFindingExclusions;

/** The accepted run, exclusions and reporting format read from one document. */
final readonly class CheckResolvedConfiguration
{
    public function __construct(
        public ResolvedRunConfiguration $run,
        public ConfiguredFindingExclusions $findingExclusions,
        public OutputFormat $outputFormat,
    ) {}
}
