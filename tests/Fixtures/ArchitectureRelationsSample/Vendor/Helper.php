<?php

declare(strict_types=1);

namespace Fixtures\ArchitectureRelationsSample\Vendor;

final class Helper
{
    public static function pay(int $amount): bool
    {
        return $amount > 0;
    }
}
