<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Run\Contract\Discovery;

use SplFileInfo;

interface GeneratedFileFilterInterface
{
    /**
     * @param list<SplFileInfo> $files
     *
     * @return list<SplFileInfo>
     */
    public function filter(array $files): array;
}
