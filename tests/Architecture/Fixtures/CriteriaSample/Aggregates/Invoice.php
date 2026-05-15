<?php

declare(strict_types=1);

namespace Fixtures\CriteriaSample\Aggregates;

use Fixtures\CriteriaSample\Suffixed\OrderRepository;

/**
 * Caught by `extends: AggregateRoot` transitively (Order extends AggregateRoot).
 * Forbidden typed dependency on OrderRepository so the rule produces an edge.
 */
final class Invoice extends Order
{
    public function attach(OrderRepository $repository): void {}
}
