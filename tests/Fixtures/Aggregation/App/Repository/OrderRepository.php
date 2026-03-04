<?php

declare(strict_types=1);

namespace Fixtures\Aggregation\App\Repository;

/**
 * OrderRepository - additional fixture for namespace-level aggregation testing
 *
 * Expected method-level metrics:
 * - findByUser():     CCN=2 (1 + 1 foreach)
 * - findByStatus():   CCN=3 (1 + 1 foreach + 1 if)
 * - updateStatus():   CCN=2 (1 + 1 if)
 *
 * Expected class-level aggregation:
 * - ccn.sum: 7 (2 + 3 + 2)
 * - ccn.max: 3
 * - ccn.avg: 2.33 (7 / 3)
 * - symbolMethodCount: 3
 *
 * Namespace-level (App\Repository) aggregation:
 * - Classes: UserRepository, OrderRepository
 * - ccn.sum: 14 (7 + 7)
 * - ccn.max: 7 (max of class sums)
 * - symbolMethodCount: 7 (4 + 3)
 */
class OrderRepository
{
    private array $orders = [];

    /**
     * CCN = 2 (base 1 + 1 foreach)
     */
    public function findByUser(int $userId): array
    {
        $result = [];
        foreach ($this->orders as $order) {
            if ($order['user_id'] === $userId) {
                $result[] = $order;
            }
        }
        return $result;
    }

    /**
     * CCN = 3 (base 1 + 1 foreach + 1 if inside foreach)
     */
    public function findByStatus(string $status): array
    {
        $result = [];
        foreach ($this->orders as $order) {
            if ($order['status'] === $status) {
                if (!empty($order['items'])) {
                    $result[] = $order;
                }
            }
        }
        return $result;
    }

    /**
     * CCN = 2 (base 1 + 1 if)
     */
    public function updateStatus(int $orderId, string $status): bool
    {
        if (!isset($this->orders[$orderId])) {
            return false;
        }

        $this->orders[$orderId]['status'] = $status;
        return true;
    }
}
