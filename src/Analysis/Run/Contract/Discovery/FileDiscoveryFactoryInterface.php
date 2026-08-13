<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Run\Contract\Discovery;

interface FileDiscoveryFactoryInterface
{
    /** @param list<string> $excludedDirs */
    public function create(array $excludedDirs = ['vendor', 'node_modules', '.git']): FileDiscoveryInterface;
}
