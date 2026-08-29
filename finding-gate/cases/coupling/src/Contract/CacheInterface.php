<?php

namespace Corpus\Coupling\Contract;

interface CacheInterface
{
    public function handle(string $payload): string;
}
