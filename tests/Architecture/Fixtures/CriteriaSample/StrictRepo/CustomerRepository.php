<?php

declare(strict_types=1);

namespace Fixtures\CriteriaSample\StrictRepo;

use Fixtures\CriteriaSample\Marker\AggregateRoot;
use Fixtures\CriteriaSample\Marker\RepositoryInterface;
use Fixtures\CriteriaSample\Tagged\Notifier;

/**
 * Caught by BOTH `suffix: Repository` AND `implements: RepositoryInterface`.
 * Used by the {@code match: all} positive integration test to verify that
 * a class satisfying every declared criterion lands in the strict layer.
 * Carries a typed dependency on Notifier so the rule has at least one
 * forbidden cross-layer edge to flag.
 */
final class CustomerRepository implements RepositoryInterface
{
    public function find(int $id): ?AggregateRoot
    {
        return null;
    }

    public function notify(Notifier $notifier): void
    {
        $notifier->notify('customer updated');
    }
}
