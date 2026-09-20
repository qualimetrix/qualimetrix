<?php

declare(strict_types=1);

namespace Fixtures\TemplateCriteriaSample\Module\Billing\Domain;

final class BillingCalculator
{
    public function total(int $amount): int
    {
        return $amount;
    }
}
