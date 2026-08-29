<?php

namespace Corpus\Layers\Repository;

class UserRepository
{
    public function find(int $id): ?string
    {
        return $id > 0 ? 'user' : null;
    }
}
