<?php

declare(strict_types=1);

namespace Fixtures\Inheritance;

use DateTimeImmutable;

/**
 * BaseEntity - root of deep inheritance hierarchy
 *
 * Expected metrics:
 * - DIT (Depth of Inheritance Tree): 0
 * - NOC (Number of Children): 1 (ChildEntity)
 * - This is the root of the hierarchy
 */
class BaseEntity
{
    protected int $id;
    protected DateTimeImmutable $createdAt;

    public function __construct(int $id)
    {
        $this->id = $id;
        $this->createdAt = new DateTimeImmutable();
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }
}
