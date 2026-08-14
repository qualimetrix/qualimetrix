<?php

declare(strict_types=1);

namespace Fixtures\Sample\Controller;

use Fixtures\Sample\Domain\User;
use Fixtures\Sample\Repository\UserRepository;
use Fixtures\Sample\Service\UserService;

/**
 * Controller intentionally violates the layered policy by depending directly on
 * the Repository layer in addition to the Service layer.
 */
final class UserController
{
    public function __construct(
        private readonly UserService $service,
        private readonly UserRepository $repository,
    ) {}

    public function show(int $id): ?User
    {
        $direct = $this->repository->find($id);
        if ($direct !== null) {
            return $direct;
        }

        return $this->service->fetch($id);
    }
}
