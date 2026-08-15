<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Parallel\Runtime;

use Qualimetrix\Infrastructure\Parallel\Contract\ParallelConfiguration;
use Qualimetrix\Infrastructure\Parallel\Contract\ParallelConfigurationStoreInterface;

final class ParallelConfigurationStore implements ParallelConfigurationStoreInterface
{
    private ParallelConfiguration $configuration;
    public function __construct()
    {
        $this->configuration = new ParallelConfiguration();
    }
    public function replace(ParallelConfiguration $configuration): void
    {
        $this->configuration = $configuration;
    }
    public function current(): ParallelConfiguration
    {
        return $this->configuration;
    }
    public function reset(): void
    {
        $this->configuration = new ParallelConfiguration();
    }
}
