<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Discovery;

use SplFileInfo;

interface FileDiscoveryInterface
{
    /**
     * Discovers PHP files in given paths.
     *
     * @param string|list<string> $paths
     *
     * @return iterable<SplFileInfo>
     */
    public function discover(string|array $paths): iterable;
}
