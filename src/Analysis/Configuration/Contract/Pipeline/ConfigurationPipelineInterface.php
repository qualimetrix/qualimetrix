<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Configuration\Contract\Pipeline;

use Qualimetrix\Analysis\Configuration\Contract\ConfigurationDocument;

interface ConfigurationPipelineInterface
{
    /**
     * Resolves the full configuration through all stages.
     */
    public function resolve(ConfigurationResolutionRequest $request): ConfigurationDocument;

}
