<?php

declare(strict_types=1);

namespace Fixtures\CouplingProject\Domain;

use Fixtures\CouplingProject\Core\AbstractEntity;

/**
 * Order entity.
 *
 * Expected metrics:
 * - Ca: 1 (OrderService depends on this)
 * - Ce: 1 (extends AbstractEntity)
 */
class Order extends AbstractEntity
{
    private float $total;

    public function __construct(int $id, float $total)
    {
        $this->id = $id;
        $this->total = $total;
    }

    public function getTotal(): float
    {
        return $this->total;
    }
}
