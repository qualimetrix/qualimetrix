<?php

declare(strict_types=1);

namespace Fixtures\ArchitectureSample\Service;

use Fixtures\ArchitectureSample\Domain\User;
use Fixtures\ArchitectureSample\Repository\UserRepository;

final class UserService
{
    public function __construct(
        private readonly UserRepository $repository,
    ) {}

    public function fetch(int $id): ?User
    {
        return $this->repository->find($id);
    }
}
