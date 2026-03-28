<?php

declare(strict_types=1);

namespace GoldenMetrics\App\Service;

use GoldenMetrics\App\Repository\UserRepository;
use InvalidArgumentException;

/**
 * Order service — cross-namespace dependency on Repository\UserRepository.
 *
 * Method-level CCN (includes __construct):
 * - __construct(): CCN=1 (no branches)
 * - placeOrder():  CCN=3 (base 1 + 1 if + 1 if)
 * - cancelOrder(): CCN=2 (base 1 + 1 if)
 *
 * Class-level:
 * - ccn.sum = 6 (1+3+2), ccn.max = 3, ccn.avg = 2.0 (6/3 methods)
 * - methodCount = 3 (includes __construct)
 * - propertyCount = 1 ($repository)
 */
class OrderService
{
    private UserRepository $repository;

    public function __construct(UserRepository $repository)
    {
        $this->repository = $repository;
    }

    /**
     * CCN = 3 (base 1 + 1 if + 1 if).
     *
     * Accesses: $repository
     */
    public function placeOrder(int $userId, array $items): ?array
    {
        $user = $this->repository->findById($userId);

        if ($user === null) {
            return null;
        }

        if (empty($items)) {
            throw new InvalidArgumentException('Items cannot be empty');
        }

        return [
            'userId' => $userId,
            'items' => $items,
            'total' => \count($items) * 100,
        ];
    }

    /**
     * CCN = 2 (base 1 + 1 if).
     */
    public function cancelOrder(array $order): bool
    {
        if (!isset($order['userId'])) {
            return false;
        }

        return true;
    }
}
