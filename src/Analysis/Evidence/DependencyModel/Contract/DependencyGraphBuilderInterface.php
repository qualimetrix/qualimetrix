<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\DependencyModel\Contract;

use Qualimetrix\Core\Symbol\LogicalClassPath;

/**
 * Builds a dependency graph from collected dependency evidence.
 */
interface DependencyGraphBuilderInterface
{
    /**
     * @param list<Dependency> $dependencies
     * @param iterable<LogicalClassPath> $logicalClassUniverse
     */
    public function build(array $dependencies, iterable $logicalClassUniverse): DependencyGraphInterface;
}
