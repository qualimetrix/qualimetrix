<?php

declare(strict_types=1);

namespace Fixtures\ArchitectureRelationsSample\Vendor;

abstract class BaseEntity
{
    public function id(): int
    {
        return 0;
    }
}
