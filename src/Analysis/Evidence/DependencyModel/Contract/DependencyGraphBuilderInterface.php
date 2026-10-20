<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\DependencyModel\Contract;

/**
 * Builds a dependency graph from collected dependency evidence.
 */
interface DependencyGraphBuilderInterface
{
    /**
     * @param list<Dependency> $dependencies
     * @param iterable<ClassLikeDeclaration> $classLikeDeclarations
     */
    public function build(array $dependencies, iterable $classLikeDeclarations): DependencyGraphBuild;
}
