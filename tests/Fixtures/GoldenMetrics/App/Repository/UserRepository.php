<?php

declare(strict_types=1);

namespace GoldenMetrics\App\Repository;

/**
 * User repository — implements interface, has properties for cohesion metrics.
 *
 * Method-level CCN:
 * - findById():  CCN=2 (base 1 + 1 if)
 * - findAll():   CCN=1 (no branches)
 * - save():      CCN=3 (base 1 + 1 if + 1 if)
 * - delete():    CCN=2 (base 1 + 1 if)
 *
 * Class-level:
 * - ccn.sum = 8, ccn.max = 3, ccn.avg = 2.0
 * - methodCount = 4
 * - propertyCount = 1 ($storage)
 */
class UserRepository implements UserRepositoryInterface
{
    /** @var array<int, array<string, mixed>> */
    private array $storage = [];

    /**
     * CCN = 2 (base 1 + 1 if).
     */
    public function findById(int $id): ?array
    {
        if (isset($this->storage[$id])) {
            return $this->storage[$id];
        }

        return null;
    }

    /**
     * CCN = 1 (no branches).
     */
    public function findAll(): array
    {
        return $this->storage;
    }

    /**
     * CCN = 3 (base 1 + 1 if + 1 if).
     */
    public function save(array $data): void
    {
        if (!isset($data['id'])) {
            $data['id'] = \count($this->storage) + 1;
        }

        if (isset($this->storage[$data['id']])) {
            $this->storage[$data['id']] = array_merge($this->storage[$data['id']], $data);
        } else {
            $this->storage[$data['id']] = $data;
        }
    }

    /**
     * CCN = 2 (base 1 + 1 if).
     */
    public function delete(int $id): bool
    {
        if (!isset($this->storage[$id])) {
            return false;
        }

        unset($this->storage[$id]);

        return true;
    }
}
