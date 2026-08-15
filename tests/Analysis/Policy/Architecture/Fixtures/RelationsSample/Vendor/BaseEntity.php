<?php

declare(strict_types=1);

namespace Fixtures\RelationsSample\Vendor;

abstract class BaseEntity
{
    public function id(): int
    {
        return 0;
    }
}
