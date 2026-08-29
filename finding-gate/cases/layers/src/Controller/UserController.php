<?php

namespace Corpus\Layers\Controller;

use Corpus\Layers\Repository\UserRepository;

class UserController
{
    public function __construct(private UserRepository $repo) {}

    public function show(int $id): string
    {
        return $this->repo->find($id) ?? 'none';
    }
}
