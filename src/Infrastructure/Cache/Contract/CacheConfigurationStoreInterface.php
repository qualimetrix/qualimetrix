<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Cache\Contract;

interface CacheConfigurationStoreInterface
{
    public function replace(CacheConfiguration $configuration): void;
    public function current(): CacheConfiguration;
    public function reset(): void;
}
