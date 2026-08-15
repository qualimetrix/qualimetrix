<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Policy\Architecture\Fixtures\ModularTopologySample\Cycle;

final readonly class CycleB
{
    public function __construct(private CycleA $next) {}
}
