<?php

declare(strict_types=1);

namespace Fixtures\RelationsSample\Domain;

use Fixtures\RelationsSample\Vendor\Product;
use RuntimeException;

final class ProductTyper
{
    public function lookup(string $sku): Product
    {
        unset($sku);
        throw new RuntimeException('not reachable in fixture');
    }
}
