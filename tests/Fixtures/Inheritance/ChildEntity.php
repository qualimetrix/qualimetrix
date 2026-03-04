<?php

declare(strict_types=1);

namespace Fixtures\Inheritance;

/**
 * ChildEntity - first level in deep inheritance hierarchy
 *
 * Expected metrics:
 * - DIT (Depth of Inheritance Tree): 1 (extends BaseEntity)
 * - NOC (Number of Children): 1 (GrandChildEntity)
 * - Inherits: 2 methods from BaseEntity
 */
class ChildEntity extends BaseEntity
{
    protected string $name;

    public function __construct(int $id, string $name)
    {
        parent::__construct($id);
        $this->name = $name;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): void
    {
        $this->name = $name;
    }
}
