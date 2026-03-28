<?php

declare(strict_types=1);

namespace GoldenMetrics\App\Repository;

/**
 * Interface for user repository — used to test abstractness metric.
 *
 * Expected metrics:
 * - This is an interface, contributes to abstractness calculation
 * - interfaceCount = 1 (file-level)
 * - classCount = 0 (interfaces don't count as classes in classCount)
 * - methodCount = 3 (abstract methods declared)
 */
interface UserRepositoryInterface
{
    public function findById(int $id): ?array;

    public function findAll(): array;

    public function save(array $data): void;
}
