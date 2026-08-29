<?php

namespace Corpus\Coupling\Contract;

interface RepositoryInterface
{
    public function handle(string $payload): string;
}
