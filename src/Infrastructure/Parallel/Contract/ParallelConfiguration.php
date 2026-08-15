<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Parallel\Contract;

final readonly class ParallelConfiguration
{
    public function __construct(public ?int $workers = null) {}
}
