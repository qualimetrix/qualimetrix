<?php

declare(strict_types=1);

namespace Fixtures\Inheritance;

/**
 * GrandChildEntity - second level in deep inheritance hierarchy
 *
 * Expected metrics:
 * - DIT (Depth of Inheritance Tree): 2 (extends ChildEntity → BaseEntity)
 * - NOC (Number of Children): 1 (GreatGrandChildEntity)
 * - Inherits: 4 methods from ancestors (2 from BaseEntity, 2 from ChildEntity)
 */
class GrandChildEntity extends ChildEntity
{
    protected string $type;

    public function __construct(int $id, string $name, string $type)
    {
        parent::__construct($id, $name);
        $this->type = $type;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getFullInfo(): array
    {
        return [
            'id' => $this->getId(),
            'name' => $this->getName(),
            'type' => $this->type,
        ];
    }
}
