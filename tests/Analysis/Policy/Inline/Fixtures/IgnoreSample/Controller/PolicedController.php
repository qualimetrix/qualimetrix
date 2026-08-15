<?php

declare(strict_types=1);

namespace Fixtures\IgnoreSample\Controller;

use Fixtures\IgnoreSample\Repository\CustomerRepository;
use Fixtures\IgnoreSample\Service\CustomerService;

/**
 * Controller that violates the layered policy: it depends directly on
 * the Repository layer, which is forbidden. NO suppression tag here — the
 * test uses this class as the "control" group that proves a violation is
 * indeed emitted.
 */
final class PolicedController
{
    public function __construct(
        private readonly CustomerService $service,
        private readonly CustomerRepository $repository,
    ) {}

    public function show(int $id): void
    {
        $this->service->fetch($id);
        $this->repository->find($id);
    }
}
