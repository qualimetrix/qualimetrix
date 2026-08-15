<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Run\Discovery;

use Qualimetrix\Analysis\Run\Contract\Discovery\FileDiscoveryFactoryInterface;
use Qualimetrix\Analysis\Run\Contract\Discovery\FileDiscoveryInterface;

final readonly class FileDiscoveryFactory implements FileDiscoveryFactoryInterface
{
    public function create(array $excludedDirs = ['vendor', 'node_modules', '.git']): FileDiscoveryInterface
    {
        return new FinderFileDiscovery($excludedDirs);
    }
}
