<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Cache\Contract;

use Qualimetrix\Analysis\Configuration\Contract\ConfigurationDocument;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Core\Path\AbsolutePath;

interface CacheConfigurationResolverInterface
{
    /** @throws ConfigurationRefusal when an enabled cache is pointed at a directory that cannot be written */
    public function resolve(ConfigurationDocument $document, AbsolutePath $projectRoot): CacheConfiguration;
}
