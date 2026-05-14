<?php

declare(strict_types=1);

namespace Fixtures\ArchitectureRelationsSample\Vendor;

final class Product
{
    public function name(): string
    {
        return 'product';
    }
}
