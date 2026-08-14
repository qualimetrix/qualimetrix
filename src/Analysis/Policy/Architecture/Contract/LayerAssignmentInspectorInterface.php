<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Architecture\Contract;

use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyGraphInterface;
use Qualimetrix\Core\Symbol\SymbolPath;

interface LayerAssignmentInspectorInterface
{
    /** @param iterable<SymbolPath> $classUniverse */
    public function inspect(DependencyGraphInterface $graph, iterable $classUniverse, SymbolPath $subject): LayerAssignment;
}
