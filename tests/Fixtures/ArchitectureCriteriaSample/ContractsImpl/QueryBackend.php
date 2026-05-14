<?php

declare(strict_types=1);

namespace Fixtures\ArchitectureCriteriaSample\ContractsImpl;

use Fixtures\ArchitectureCriteriaSample\Aggregates\Order;
use Fixtures\ArchitectureCriteriaSample\Marker\RepositoryInterface;

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
