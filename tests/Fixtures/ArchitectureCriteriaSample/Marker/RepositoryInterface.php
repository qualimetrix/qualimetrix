<?php

declare(strict_types=1);

namespace Fixtures\ArchitectureCriteriaSample\Marker;

interface RepositoryInterface
{
    public function find(int $id): ?object;
}
