<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Architecture\Fixtures\ModularTopologySample\Boundary\Billing\Contract;

interface BillingGateway
{
    public function charge(): void;
}
