<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Duplication;

use Qualimetrix\Core\Duplication\DuplicateBlock;
use SplFileInfo;

interface DuplicationDetectorInterface
{
    /**
     * Detects duplicate code blocks across the given files.
     *
     * @param list<SplFileInfo> $files
     *
     * @return list<DuplicateBlock>
     */
    public function detect(array $files): array;
}
