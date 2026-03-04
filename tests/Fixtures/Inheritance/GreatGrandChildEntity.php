<?php

declare(strict_types=1);

namespace Fixtures\Inheritance;

/**
 * GreatGrandChildEntity - third level in deep inheritance hierarchy
 *
 * Expected metrics:
 * - DIT (Depth of Inheritance Tree): 3 (extends GrandChildEntity → ChildEntity → BaseEntity)
 * - NOC (Number of Children): 1 (VeryDeepEntity)
 * - Inherits: 6 methods from ancestors
 * - WARNING: DIT approaching dangerous levels (typical threshold: 4-5)
 */
class GreatGrandChildEntity extends GrandChildEntity
{
    protected string $status;

    public function __construct(int $id, string $name, string $type, string $status)
    {
        parent::__construct($id, $name, $type);
        $this->status = $status;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function activate(): void
    {
        $this->status = 'active';
    }
}
