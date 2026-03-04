<?php

declare(strict_types=1);

namespace Fixtures\Aggregation\App\Service;

use InvalidArgumentException;

/**
 * UserService - fixture for testing metric aggregation
 *
 * Expected method-level metrics:
 * - findById():   CCN=2 (1 + 1 if statement)
 * - findByEmail(): CCN=3 (1 + 1 if + 1 foreach)
 * - create():     CCN=5 (1 + 2 if + 1 foreach + 1 if inside foreach)
 *
 * Expected class-level aggregation:
 * - ccn.sum: 10 (2 + 3 + 5)
 * - ccn.max: 5
 * - ccn.avg: 3.33 (10 / 3)
 * - symbolMethodCount: 3
 */
class UserService
{
    /**
     * CCN = 2 (base 1 + 1 if)
     */
    public function findById(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }

        return ['id' => $id, 'name' => 'User ' . $id];
    }

    /**
     * CCN = 3 (base 1 + 1 if + 1 foreach)
     */
    public function findByEmail(string $email): ?array
    {
        $users = $this->getAllUsers();

        if (empty($email)) {
            return null;
        }

        foreach ($users as $user) {
            if ($user['email'] === $email) {
                return $user;
            }
        }

        return null;
    }

    /**
     * CCN = 5 (base 1 + 1 if + 1 if + 1 foreach + 1 if inside foreach)
     */
    public function create(array $data): array
    {
        if (!isset($data['email'])) {
            throw new InvalidArgumentException('Email required');
        }

        if (!isset($data['name'])) {
            $data['name'] = 'Unnamed';
        }

        $validatedData = [];
        foreach ($data as $key => $value) {
            if (!empty($value)) {
                $validatedData[$key] = $value;
            }
        }

        return array_merge($validatedData, ['id' => rand(1, 1000)]);
    }

    private function getAllUsers(): array
    {
        return [
            ['id' => 1, 'email' => 'user1@test.com'],
            ['id' => 2, 'email' => 'user2@test.com'],
        ];
    }
}
