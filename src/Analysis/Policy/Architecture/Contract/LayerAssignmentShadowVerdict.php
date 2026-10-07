<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Architecture\Contract;

/** An established later match and its exact first-match exemption. */
final readonly class LayerAssignmentShadowVerdict
{
    public function __construct(
        public LayerAssignmentMatch $match,
        public ?ShadowExemption $exemption,
    ) {}

    public function reported(): bool
    {
        return $this->exemption === null;
    }
}
