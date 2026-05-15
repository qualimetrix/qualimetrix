<?php

declare(strict_types=1);

namespace Fixtures\CriteriaSample\Tagged;

use Fixtures\CriteriaSample\ContractsImpl\QueryBackend;
use Fixtures\CriteriaSample\Marker\ServiceTag;

/**
 * Caught by `attributes: ServiceTag`. Carries a forbidden typed dependency
 * on QueryBackend so the rule has an edge to flag.
 */
#[ServiceTag('mail-notifier')]
final class Notifier
{
    public function dispatch(QueryBackend $backend): void {}

    public function notify(string $message): void {}
}
