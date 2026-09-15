<?php

declare(strict_types=1);

namespace Fixture\Service;

use Fixture\Repository\UserRepository;

/**
 * The constructor dependency is the typed `edge` a layer violation publishes;
 * the `var_dump` is a file-level finding with no class context.
 */
final class Worker
{
    public function run(UserRepository $repository): void
    {
        var_dump($repository->find(1));
    }
}
