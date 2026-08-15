<?php

declare(strict_types=1);

namespace Fixtures\CriteriaSample\Marker;

interface RepositoryInterface
{
    public function find(int $id): ?object;
}
