<?php

declare(strict_types=1);

namespace Qualimetrix\Core\FileTarget\Resolution;

final readonly class PathExposure
{
    public function __construct(public string $directory, public string $changedBy) {}
}
