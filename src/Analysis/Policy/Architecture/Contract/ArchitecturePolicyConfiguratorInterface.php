<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Architecture\Contract;

use Qualimetrix\Analysis\Configuration\Contract\ConfigurationDocument;

interface ArchitecturePolicyConfiguratorInterface
{
    /** @return list<ArchitectureConfigurationWarning> */
    public function configure(ConfigurationDocument $document): array;
}
