<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Architecture\Contract;

final readonly class LayerAssignment
{
    /** @param list<LayerAssignmentMatch> $matches */
    public function __construct(public array $matches, public bool $hasLayers) {}
}
