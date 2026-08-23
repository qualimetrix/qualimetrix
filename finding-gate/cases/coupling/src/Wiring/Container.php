<?php

namespace Corpus\Coupling\Wiring;

use Corpus\Coupling\Service\CacheService;
use Corpus\Coupling\Service\ReportService;
use Corpus\Coupling\Service\UserService;

class Container
{
    public function __construct(
        private UserService $users,
        private ReportService $reports,
        private CacheService $caches,
    ) {}

    public function run(string $input): string
    {
        return $this->users->register($input) . $this->reports->build($input) . $this->caches->warm($input);
    }
}
