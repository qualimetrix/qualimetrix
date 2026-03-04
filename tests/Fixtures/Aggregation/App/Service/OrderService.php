<?php

declare(strict_types=1);

namespace Fixtures\Aggregation\App\Service;

use InvalidArgumentException;

/**
 * OrderService - fixture for testing metric aggregation
 *
 * Expected method-level metrics:
 * - validate():  CCN=1 (no branches, just return)
 * - process():   CCN=8 (1 + 1 if + 1 foreach + 2 if inside foreach + 1 if + 1 foreach + 1 if)
 *
 * Expected class-level aggregation:
 * - ccn.sum: 9 (1 + 8)
 * - ccn.max: 8
 * - ccn.avg: 4.5 (9 / 2)
 * - symbolMethodCount: 2
 */
class OrderService
{
    /**
     * CCN = 1 (no branches)
     */
    public function validate(array $order): bool
    {
        return !empty($order);
    }

    /**
     * CCN = 8 (complex branching logic)
     * Breakdown:
     * - base: 1
     * - if (empty($items)): +1
     * - foreach ($items): +1
     * - if ($item['quantity'] <= 0): +1
     * - if ($item['price'] <= 0): +1
     * - if ($total > 1000): +1
     * - foreach ($items): +1
     * - if ($item['discount']): +1
     * Total: 8
     */
    public function process(array $items): array
    {
        if (empty($items)) {
            return ['status' => 'empty', 'total' => 0];
        }

        $total = 0;
        foreach ($items as $item) {
            if ($item['quantity'] <= 0) {
                continue;
            }
            if ($item['price'] <= 0) {
                throw new InvalidArgumentException('Invalid price');
            }
            $total += $item['quantity'] * $item['price'];
        }

        if ($total > 1000) {
            $total *= 0.9; // 10% discount
        }

        $processedItems = [];
        foreach ($items as $item) {
            if (isset($item['discount']) && $item['discount'] > 0) {
                $item['price'] *= (1 - $item['discount']);
            }
            $processedItems[] = $item;
        }

        return [
            'status' => 'processed',
            'total' => $total,
            'items' => $processedItems,
        ];
    }
}
