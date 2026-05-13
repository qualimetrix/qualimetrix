<?php

declare(strict_types=1);

namespace Fixtures\ArchitectureSample\Repository;

use Fixtures\ArchitectureSample\Domain\User;

final class UserRepository
{
    public function find(int $id): ?User
    {
        return new User($id, 'fixture');
    }
}
