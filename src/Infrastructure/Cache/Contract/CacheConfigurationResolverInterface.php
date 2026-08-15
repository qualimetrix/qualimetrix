<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Cache\Contract;

use Qualimetrix\Analysis\Configuration\Contract\ConfigurationDocument;
use Qualimetrix\Core\Path\AbsolutePath;

interface CacheConfigurationResolverInterface
{
    public function resolve(ConfigurationDocument $document, AbsolutePath $projectRoot): CacheConfiguration;
}
