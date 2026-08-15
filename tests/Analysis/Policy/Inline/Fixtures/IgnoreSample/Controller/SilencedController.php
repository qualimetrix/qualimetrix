<?php

declare(strict_types=1);

namespace Fixtures\IgnoreSample\Controller;

use Fixtures\IgnoreSample\Repository\CustomerRepository;
use Fixtures\IgnoreSample\Service\CustomerService;

/**
 * Controller that violates the layered policy but suppresses the
 * `architecture.layer-violation` rule via an inline tag. The test treats
 * this as the "treatment" group: a working suppression filter must drop
 * the violation, leaving only the `PolicedController` violation in the
 * result set.
 *
 * @qmx-ignore architecture.layer-violation Intentional shortcut while we migrate the legacy code path.
 */
final class SilencedController
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
