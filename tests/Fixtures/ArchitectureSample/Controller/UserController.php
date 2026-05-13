<?php

declare(strict_types=1);

namespace Fixtures\ArchitectureSample\Controller;

use Fixtures\ArchitectureSample\Domain\User;
use Fixtures\ArchitectureSample\Repository\UserRepository;
use Fixtures\ArchitectureSample\Service\UserService;

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
