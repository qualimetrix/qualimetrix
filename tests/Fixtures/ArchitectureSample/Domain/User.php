<?php

declare(strict_types=1);

namespace Fixtures\ArchitectureSample\Domain;

final class User
{
    public function __construct(
        public readonly int $id,
        public readonly string $name,
    ) {}
}
