<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Architecture\Layer;

use Qualimetrix\Analysis\Policy\Architecture\Contract\ShadowExemption;

/** A shadow is reported exactly when no first-match exemption applies. */
final readonly class LayerShadowVerdict
{
    public function __construct(
        public LayerMatch $earlier,
        public LayerMatch $later,
        public ?ShadowExemption $exemption,
    ) {}
}
