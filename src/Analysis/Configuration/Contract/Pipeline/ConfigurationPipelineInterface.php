<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Configuration\Contract\Pipeline;

use Qualimetrix\Analysis\Configuration\Contract\TransitionalResolvedConfiguration;

interface ConfigurationPipelineInterface
{
    /**
     * Resolves the full configuration through all stages.
     */
    public function resolve(ConfigurationContext $context): TransitionalResolvedConfiguration;

}
