<?php

declare(strict_types=1);

namespace Fixtures\IgnoreSample\Service;

use Fixtures\IgnoreSample\Repository\CustomerRepository;

final class CustomerService
{
    public function __construct(private readonly CustomerRepository $repository) {}

    public function fetch(int $id): ?object
    {
        return $this->repository->find($id);
    }
}
