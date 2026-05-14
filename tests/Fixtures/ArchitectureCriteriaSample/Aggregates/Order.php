<?php

declare(strict_types=1);

namespace Fixtures\ArchitectureCriteriaSample\Aggregates;

use Fixtures\ArchitectureCriteriaSample\Marker\AggregateRoot;
use Fixtures\ArchitectureCriteriaSample\Tagged\Notifier;

/**
 * Caught by `extends: AggregateRoot` (direct parent). Carries a forbidden
 * typed dependency on Notifier so the rule has an edge to flag.
 */
final class Order extends AggregateRoot
{
    public function notify(Notifier $notifier): void
    {
        $notifier->notify('order created');
    }
}
