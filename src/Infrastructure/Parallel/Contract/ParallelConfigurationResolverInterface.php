<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Parallel\Contract;

use Qualimetrix\Analysis\Configuration\Contract\ConfigurationDocument;

interface ParallelConfigurationResolverInterface
{
    public function resolve(ConfigurationDocument $document): ParallelConfiguration;
}
