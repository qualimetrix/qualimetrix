<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Run\Contract\Discovery;

use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Core\Pattern\PathPattern;

interface FileDiscoveryFactoryInterface
{
    /** @param list<PathPattern> $excludedDirectories */
    public function create(AbsolutePath $projectRoot, array $excludedDirectories): FileDiscoveryInterface;
}
