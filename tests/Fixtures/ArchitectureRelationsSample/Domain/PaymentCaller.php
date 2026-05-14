<?php

declare(strict_types=1);

namespace Fixtures\ArchitectureRelationsSample\Domain;

use Fixtures\ArchitectureRelationsSample\Vendor\Helper;

final class PaymentCaller
{
    public function execute(int $amount): bool
    {
        return Helper::pay($amount);
    }
}
