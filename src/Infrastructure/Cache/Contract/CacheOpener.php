<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Cache\Contract;

use Qualimetrix\Core\Path\AbsolutePath;

interface CacheOpener
{
    public function open(): ?AbsolutePath;
}
