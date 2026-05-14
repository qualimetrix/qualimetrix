<?php

declare(strict_types=1);

namespace Fixtures\ArchitectureCriteriaSample\Marker;

abstract class AggregateRoot
{
    public function __construct(public readonly int $id) {}
}
