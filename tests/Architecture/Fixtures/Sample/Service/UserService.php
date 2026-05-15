<?php

declare(strict_types=1);

namespace Fixtures\Sample\Service;

use Fixtures\Sample\Domain\User;
use Fixtures\Sample\Repository\UserRepository;

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
