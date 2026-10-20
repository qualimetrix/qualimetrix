<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Measurement\Contract;

use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\ClassLikeDeclaration;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\Dependency;

final readonly class CollectionOutput
{
    /**
     * @param list<Dependency> $dependencies
     * @param list<ClassLikeDeclaration> $classLikeDeclarations
     */
    public function __construct(
        public MetricBag $metrics,
        public array $dependencies,
        public array $classLikeDeclarations,
    ) {}
}
