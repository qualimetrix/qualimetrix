<?php

declare(strict_types=1);

namespace Fixtures\ArchitectureExcludeSample\Module\Order\Domain;

use Fixtures\ArchitectureExcludeSample\Marker\Marker;

final class Order
{
    public function __construct(public Marker $marker) {}
}
