<?php

declare(strict_types=1);

namespace Fixtures\Aggregation\App\Repository;

use RuntimeException;

/**
 * UserRepository - fixture for testing metric aggregation
 *
 * Expected method-level metrics:
 * - findAll():    CCN=1 (no branches)
 * - findOne():    CCN=1 (no branches)
 * - save():       CCN=2 (1 + 1 if)
 * - delete():     CCN=3 (1 + 1 if + 1 if)
 *
 * Expected class-level aggregation:
 * - ccn.sum: 7 (1 + 1 + 2 + 3)
 * - ccn.max: 3
 * - ccn.avg: 1.75 (7 / 4)
 * - symbolMethodCount: 4
 */
class UserRepository
{
    private array $storage = [];

    /**
     * CCN = 1 (no branches)
     */
    public function findAll(): array
    {
        return $this->storage;
    }

    /**
     * CCN = 1 (no branches)
     */
    public function findOne(int $id): ?array
    {
        return $this->storage[$id] ?? null;
    }

    /**
     * CCN = 2 (base 1 + 1 if)
     */
    public function save(array $user): void
    {
        if (!isset($user['id'])) {
            $user['id'] = \count($this->storage) + 1;
        }

        $this->storage[$user['id']] = $user;
    }

    /**
     * CCN = 3 (base 1 + 1 if + 1 if)
     */
    public function delete(int $id): bool
    {
        if (!isset($this->storage[$id])) {
            return false;
        }

        if ($this->storage[$id]['protected'] ?? false) {
            throw new RuntimeException('Cannot delete protected user');
        }

        unset($this->storage[$id]);
        return true;
    }
}
