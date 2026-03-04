<?php

declare(strict_types=1);

namespace Fixtures\Inheritance;

/**
 * VeryDeepEntity - fourth level in deep inheritance hierarchy
 *
 * Expected metrics:
 * - DIT (Depth of Inheritance Tree): 4 (extends GreatGrandChildEntity → ... → BaseEntity)
 * - NOC (Number of Children): 1 (ExtremelyDeepEntity)
 * - Inherits: 8 methods from ancestors
 * - VIOLATION: DIT exceeds recommended threshold (4-5)
 * - Inheritance chain: BaseEntity → ChildEntity → GrandChildEntity → GreatGrandChildEntity → VeryDeepEntity
 */
class VeryDeepEntity extends GreatGrandChildEntity
{
    protected array $metadata;

    public function __construct(int $id, string $name, string $type, string $status, array $metadata)
    {
        parent::__construct($id, $name, $type, $status);
        $this->metadata = $metadata;
    }

    public function getMetadata(): array
    {
        return $this->metadata;
    }

    public function addMetadata(string $key, mixed $value): void
    {
        $this->metadata[$key] = $value;
    }
}
