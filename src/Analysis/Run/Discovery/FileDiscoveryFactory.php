<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Run\Discovery;

use Qualimetrix\Analysis\Run\Contract\Discovery\FileDiscoveryFactoryInterface;
use Qualimetrix\Analysis\Run\Contract\Discovery\FileDiscoveryInterface;
use Qualimetrix\Core\Path\AbsolutePath;

final readonly class FileDiscoveryFactory implements FileDiscoveryFactoryInterface
{
    public function create(AbsolutePath $projectRoot, array $excludedDirectories): FileDiscoveryInterface
    {
        return new FinderFileDiscovery(new DirectoryPruner($projectRoot, $excludedDirectories));
    }
}
