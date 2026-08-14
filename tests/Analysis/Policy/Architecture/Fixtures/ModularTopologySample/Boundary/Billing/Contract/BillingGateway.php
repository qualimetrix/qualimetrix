<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Policy\Architecture\Fixtures\ModularTopologySample\Boundary\Billing\Contract;

interface BillingGateway
{
    public function charge(): void;
}
