<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Cohesion\Contract;

use Qualimetrix\Analysis\Finding\Contract\Configuration\FindingConfiguration;

interface LcomCollectionConfigurationResolverInterface
{
    public function resolve(FindingConfiguration $configuration): LcomCollectionConfiguration;
}
