<?php

declare(strict_types=1);

namespace Fixtures\Aggregation\App\Service;

use RuntimeException;

/**
 * PaymentService - additional fixture for namespace-level aggregation testing
 *
 * Expected method-level metrics:
 * - authorize():  CCN=4 (1 + 1 if + 1 if + 1 if)
 * - charge():     CCN=6 (1 + 1 if + 1 if + 1 switch with 3 cases)
 *
 * Expected class-level aggregation:
 * - ccn.sum: 10 (4 + 6)
 * - ccn.max: 6
 * - ccn.avg: 5.0 (10 / 2)
 * - symbolMethodCount: 2
 *
 * Namespace-level (App\Service) aggregation:
 * - Classes: UserService, OrderService, PaymentService
 * - ccn.sum: 29 (10 + 9 + 10)
 * - ccn.max: 10 (max of class sums)
 * - symbolMethodCount: 7 (3 + 2 + 2)
 */
class PaymentService
{
    /**
     * CCN = 4 (base 1 + 3 if)
     */
    public function authorize(array $payment): bool
    {
        if ($payment['amount'] <= 0) {
            return false;
        }

        if (empty($payment['method'])) {
            return false;
        }

        if (!isset($payment['card'])) {
            return false;
        }

        return true;
    }

    /**
     * CCN = 6 (base 1 + 2 if + 3 case in switch)
     * Switch adds +1 for each case (not including default)
     */
    public function charge(array $payment): array
    {
        if (!$this->authorize($payment)) {
            throw new RuntimeException('Payment not authorized');
        }

        $result = ['status' => 'pending'];

        if ($payment['amount'] > 10000) {
            $result['requires_approval'] = true;
        }

        switch ($payment['method']) {
            case 'card':
                $result['processor'] = 'stripe';
                break;
            case 'paypal':
                $result['processor'] = 'paypal';
                break;
            case 'bank':
                $result['processor'] = 'bank_transfer';
                break;
            default:
                $result['processor'] = 'unknown';
        }

        return $result;
    }
}
