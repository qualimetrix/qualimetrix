<?php

namespace Corpus\Coupling\Wiring;

use Corpus\Coupling\Kernel\Clock;

class Bootstrap
{
    public function __construct(
        private Container $container,
        private Clock $clock,
    ) {}

    public function boot(): string
    {
        return $this->container->run($this->clock->value());
    }
}
