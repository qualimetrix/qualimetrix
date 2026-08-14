<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Policy\Architecture\Fixtures\ModularTopologySample\Boundary\Orders\Internal;

use Qualimetrix\Tests\Analysis\Policy\Architecture\Fixtures\ModularTopologySample\Boundary\Billing\Internal\BillingEngine;

final readonly class ForbiddenSiblingConsumer
{
    public function __construct(private BillingEngine $billing) {}

    public function checkout(): void
    {
        $this->billing->charge();
    }
}
