<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Architecture\Fixtures\ModularTopologySample\Cycle;

final readonly class CycleB
{
    public function __construct(private CycleA $next) {}
}
