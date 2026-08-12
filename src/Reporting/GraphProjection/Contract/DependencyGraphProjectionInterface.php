<?php

declare(strict_types=1);

namespace Qualimetrix\Reporting\GraphProjection\Contract;

use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyGraphInterface;

/**
 * Projects a dependency graph into a requested output representation.
 */
interface DependencyGraphProjectionInterface
{
    public function project(DependencyGraphInterface $graph, GraphProjectionRequest $request): string;
}
