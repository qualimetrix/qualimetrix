<?php

declare(strict_types=1);

namespace Fixtures\CouplingProject\Service;

use Fixtures\CouplingProject\Domain\Order;

/**
 * Order service.
 *
 * Expected metrics:
 * - Ca: 0 (nobody depends on this)
 * - Ce: 1 (uses Order)
 * - Instability: 1.0
 */
class OrderService
{
    public function create(float $total): Order
    {
        return new Order(1, $total);
    }
}
