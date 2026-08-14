<?php

declare(strict_types=1);

namespace Fixtures\RelationsSample\Domain;

use Fixtures\RelationsSample\Vendor\Helper;

final class PaymentCaller
{
    public function execute(int $amount): bool
    {
        return Helper::pay($amount);
    }
}
