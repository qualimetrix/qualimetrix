<?php

declare(strict_types=1);

namespace Fixtures\CriteriaSample\Suffixed;

use Fixtures\CriteriaSample\Marker\AggregateRoot;
use Fixtures\CriteriaSample\Tagged\Notifier;

/**
 * Caught by `suffix: Repository`. Does not implement RepositoryInterface so
 * the implements criterion stays silent. Carries typed dependencies on a
 * Tagged class AND a Marker class so the rule has at least one forbidden
 * edge under any sensible layer configuration.
 */
final class OrderRepository
{
    public function find(int $id, Notifier $notifier): ?AggregateRoot
    {
        return null;
    }
}
