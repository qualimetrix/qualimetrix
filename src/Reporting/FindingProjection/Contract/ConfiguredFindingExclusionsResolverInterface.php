<?php

declare(strict_types=1);

namespace Qualimetrix\Reporting\FindingProjection\Contract;

use Qualimetrix\Analysis\Configuration\Contract\ConfigurationDocument;

interface ConfiguredFindingExclusionsResolverInterface
{
    public function resolve(ConfigurationDocument $document): ConfiguredFindingExclusions;
}
