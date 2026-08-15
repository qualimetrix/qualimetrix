<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Policy\Architecture\Fixtures\ModularTopologySample\Boundary\Orders\Internal;

use Qualimetrix\Tests\Analysis\Policy\Architecture\Fixtures\ModularTopologySample\Boundary\Billing\Contract\BillingGateway;

final readonly class AllowedContractConsumer
{
    public function __construct(private BillingGateway $billing) {}

    public function checkout(): void
    {
        $this->billing->charge();
    }
}
