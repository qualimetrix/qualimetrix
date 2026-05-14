<?php

declare(strict_types=1);

namespace Fixtures\ArchitectureExcludeSample\Service;

use Fixtures\ArchitectureExcludeSample\Marker\Marker;

final class UserService
{
    public function __construct(public Marker $marker) {}
}
