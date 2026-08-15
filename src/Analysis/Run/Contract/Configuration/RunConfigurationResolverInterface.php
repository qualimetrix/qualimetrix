<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Run\Contract\Configuration;

use Qualimetrix\Analysis\Configuration\Contract\ConfigurationDocument;

interface RunConfigurationResolverInterface
{
    public function resolve(ConfigurationDocument $document): RunConfiguration;
}
