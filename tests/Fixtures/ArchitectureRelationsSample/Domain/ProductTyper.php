<?php

declare(strict_types=1);

namespace Fixtures\ArchitectureRelationsSample\Domain;

use Fixtures\ArchitectureRelationsSample\Vendor\Product;
use RuntimeException;

final class ProductTyper
{
    public function lookup(string $sku): Product
    {
        unset($sku);
        throw new RuntimeException('not reachable in fixture');
    }
}
