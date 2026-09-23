<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Architecture\LayerViolation\Observation;

use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\Dependency;
use Qualimetrix\Analysis\Policy\Architecture\Layer\LayerMatch;

/**
 * One dependency edge the allow-list rejects, with the layer each end was
 * assigned to.
 */
final readonly class ForbiddenEdge
{
    public function __construct(
        public Dependency $dependency,
        public LayerMatch $fromMatch,
        public LayerMatch $toMatch,
    ) {}
}
