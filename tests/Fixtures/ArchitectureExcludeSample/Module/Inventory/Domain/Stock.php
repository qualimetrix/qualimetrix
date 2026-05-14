<?php

declare(strict_types=1);

namespace Fixtures\ArchitectureExcludeSample\Module\Inventory\Domain;

use Fixtures\ArchitectureExcludeSample\Marker\Marker;

final class Stock
{
    public function __construct(public Marker $marker) {}
}
