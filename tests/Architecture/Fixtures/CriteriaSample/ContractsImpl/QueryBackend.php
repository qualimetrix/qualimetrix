<?php

declare(strict_types=1);

namespace Fixtures\CriteriaSample\ContractsImpl;

use Fixtures\CriteriaSample\Aggregates\Order;
use Fixtures\CriteriaSample\Marker\RepositoryInterface;

/**
 * Caught by `implements: RepositoryInterface`. Has a typed property pointing
 * at an aggregate so a forbidden cross-layer edge is produced — the
 * violation is what we use as evidence the classification happened.
 */
final class QueryBackend implements RepositoryInterface
{
    public function find(int $id): ?Order
    {
        return null;
    }
}
