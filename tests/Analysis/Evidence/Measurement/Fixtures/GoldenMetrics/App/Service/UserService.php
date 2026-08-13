<?php

declare(strict_types=1);

namespace GoldenMetrics\App\Service;

use GoldenMetrics\App\Repository\UserRepository;
use InvalidArgumentException;

/**
 * User service — cross-namespace dependency on Repository\UserRepository.
 *
 * Has properties accessed by methods (for TCC/LCC).
 *
 * Method-level CCN (includes __construct):
 * - __construct(): CCN=1 (no branches)
 * - getUser():     CCN=2 (base 1 + 1 if)
 * - createUser():  CCN=4 (base 1 + 1 if + 1 if + 1 if)
 * - listUsers():   CCN=5 (base 1 + 1 foreach + 1 if + 1 || + 1 ??)
 *
 * Class-level:
 * - ccn.sum = 12 (1+2+4+5), ccn.max = 5, ccn.avg = 3.0 (12/4 methods)
 * - methodCount = 3
 * - propertyCount = 2 ($repository, $defaultRole)
 */
class UserService
{
    private UserRepository $repository;

    private string $defaultRole = 'user';

    public function __construct(UserRepository $repository)
    {
        $this->repository = $repository;
    }

    /**
     * CCN = 2 (base 1 + 1 if).
     *
     * Accesses: $repository
     */
    public function getUser(int $id): ?array
    {
        $user = $this->repository->findById($id);

        if ($user === null) {
            return null;
        }

        return $user;
    }

    /**
     * CCN = 4 (base 1 + 1 if + 1 if + 1 if).
     *
     * Accesses: $repository, $defaultRole
     */
    public function createUser(string $name, ?string $email = null, ?string $role = null): array
    {
        if (empty($name)) {
            throw new InvalidArgumentException('Name is required');
        }

        $data = ['name' => $name];

        if ($email !== null) {
            $data['email'] = $email;
        }

        if ($role !== null) {
            $data['role'] = $role;
        } else {
            $data['role'] = $this->defaultRole;
        }

        $this->repository->save($data);

        return $data;
    }

    /**
     * CCN = 5 (base 1 + 1 foreach + 1 if + 1 || + 1 ??).
     *
     * Accesses: $repository
     */
    public function listUsers(string $roleFilter = ''): array
    {
        $users = $this->repository->findAll();
        $result = [];

        foreach ($users as $user) {
            if ($roleFilter === '' || ($user['role'] ?? '') === $roleFilter) {
                $result[] = $user;
            }
        }

        return $result;
    }
}
