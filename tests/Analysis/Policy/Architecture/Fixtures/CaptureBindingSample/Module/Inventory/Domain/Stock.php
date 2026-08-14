<?php

declare(strict_types=1);

namespace Fixtures\CaptureBindingSample\Module\Inventory\Domain;

final class Stock
{
    public function __construct(public string $sku, public int $quantity) {}
}
