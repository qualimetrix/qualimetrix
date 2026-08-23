<?php

namespace Corpus\Coupling\Wiring;

use Corpus\Coupling\Kernel\Uuid;
use Corpus\Coupling\Service\UserService;

class Factory
{
    public function __construct(
        private UserService $users,
        private Uuid $uuid,
    ) {}

    public function create(): string
    {
        return $this->users->register($this->uuid->value());
    }
}
