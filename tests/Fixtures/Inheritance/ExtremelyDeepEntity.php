<?php

declare(strict_types=1);

namespace Fixtures\Inheritance;

/**
 * ExtremelyDeepEntity - fifth level in deep inheritance hierarchy
 *
 * Expected metrics:
 * - DIT (Depth of Inheritance Tree): 5 (extends VeryDeepEntity → ... → BaseEntity)
 * - NOC (Number of Children): 0 (leaf node)
 * - Inherits: 10 methods from ancestors
 * - CRITICAL VIOLATION: DIT severely exceeds recommended threshold
 * - Full chain: BaseEntity → ChildEntity → GrandChildEntity → GreatGrandChildEntity → VeryDeepEntity → ExtremelyDeepEntity
 * - This represents a problematic inheritance depth that should trigger errors
 */
class ExtremelyDeepEntity extends VeryDeepEntity
{
    protected string $version;

    public function __construct(
        int $id,
        string $name,
        string $type,
        string $status,
        array $metadata,
        string $version,
    ) {
        parent::__construct($id, $name, $type, $status, $metadata);
        $this->version = $version;
    }

    public function getVersion(): string
    {
        return $this->version;
    }

    public function getCompleteHierarchy(): array
    {
        return [
            'id' => $this->getId(),
            'name' => $this->getName(),
            'type' => $this->getType(),
            'status' => $this->getStatus(),
            'metadata' => $this->getMetadata(),
            'version' => $this->version,
            'created' => $this->getCreatedAt()->format('Y-m-d H:i:s'),
        ];
    }
}
