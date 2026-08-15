<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Cache\Contract;

use Qualimetrix\Core\Path\AbsolutePath;

final readonly class CacheConfiguration
{
    public function __construct(public AbsolutePath $directory, public bool $enabled = true) {}
}
