<?php

namespace Corpus\Coupling\Service;

use Corpus\Coupling\Contract\CacheInterface;
use Corpus\Coupling\Kernel\Clock;
use Corpus\Coupling\Kernel\Logger;

class CacheService
{
    public function __construct(
        private CacheInterface $cache,
        private Clock $clock,
        private Logger $logger,
    ) {}

    public function warm(string $key): string
    {
        $this->logger->value();

        return $this->cache->handle($key) . $this->clock->value();
    }
}
