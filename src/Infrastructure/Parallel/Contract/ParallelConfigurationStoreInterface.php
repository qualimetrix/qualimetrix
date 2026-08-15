<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Parallel\Contract;

interface ParallelConfigurationStoreInterface
{
    public function replace(ParallelConfiguration $configuration): void;
    public function current(): ParallelConfiguration;
    public function reset(): void;
}
