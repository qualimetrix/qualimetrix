<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Run\Contract\Discovery;

use SplFileInfo;

interface GeneratedFileFilterInterface
{
    /** True for generated, false for ordinary, null when the header cannot be read. */
    public function isGenerated(SplFileInfo $file): ?bool;
}
