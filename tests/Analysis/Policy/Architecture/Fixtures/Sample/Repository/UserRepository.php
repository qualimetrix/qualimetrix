<?php

declare(strict_types=1);

namespace Fixtures\Sample\Repository;

use Fixtures\Sample\Domain\User;

final class UserRepository
{
    public function find(int $id): ?User
    {
        return new User($id, 'fixture');
    }
}
