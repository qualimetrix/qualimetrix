<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Architecture\Contract;

final readonly class LayerAssignmentMatch
{
    /** @param non-empty-list<string> $criteria */
    public function __construct(public string $layerName, public array $criteria) {}
}
