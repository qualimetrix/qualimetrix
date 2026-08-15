<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Finding\Contract\Configuration;

use Qualimetrix\Analysis\Configuration\Contract\ConfigurationDocument;

interface FindingConfigurationResolverInterface
{
    public function resolve(ConfigurationDocument $document, FindingCliOverrides $cliOverrides): FindingConfiguration;
}
