<?php

declare(strict_types=1);

namespace Fixtures\ExcludeSample\Module\Inventory\Domain;

use Fixtures\ExcludeSample\Marker\Marker;

final class Stock
{
    public function __construct(public Marker $marker) {}
}
