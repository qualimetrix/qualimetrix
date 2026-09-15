<?php

declare(strict_types=1);

namespace Fixture\Repository;

final class UserRepository
{
    public function find(int $id): int
    {
        return $id;
    }
}
