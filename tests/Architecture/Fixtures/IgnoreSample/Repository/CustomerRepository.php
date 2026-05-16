<?php

declare(strict_types=1);

namespace Fixtures\IgnoreSample\Repository;

use Fixtures\IgnoreSample\Domain\Customer;

final class CustomerRepository
{
    public function find(int $id): ?Customer
    {
        return null;
    }
}
