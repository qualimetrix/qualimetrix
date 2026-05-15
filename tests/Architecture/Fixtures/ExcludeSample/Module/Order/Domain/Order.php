<?php

declare(strict_types=1);

namespace Fixtures\ExcludeSample\Module\Order\Domain;

use Fixtures\ExcludeSample\Marker\Marker;

final class Order
{
    public function __construct(public Marker $marker) {}
}
