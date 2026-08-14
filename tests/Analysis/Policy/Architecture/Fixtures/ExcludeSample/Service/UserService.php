<?php

declare(strict_types=1);

namespace Fixtures\ExcludeSample\Service;

use Fixtures\ExcludeSample\Marker\Marker;

final class UserService
{
    public function __construct(public Marker $marker) {}
}
