<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\DependencyModel;

use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\ClassLikeDeclaration;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\Dependency;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyType;

/**
 * Exact named declaration facts and the dependencies declarations state about themselves.
 *
 * @qmx-threshold health.cohesion 40.0 -- Independent immutable observations or stateless declaration operations intentionally have no shared instance-field graph; this finite class boundary keeps a further cohesion loss visible.
 */
final readonly class DeclarationEvidence
{
    /**
     * @param list<Dependency> $dependencies
     * @param list<ClassLikeDeclaration> $classLikeDeclarations
     */
    public function __construct(private array $dependencies, private array $classLikeDeclarations) {}

    /**
     * @param array<Dependency> $dependencies
     *
     * @return list<Dependency>
     */
    public static function declarationsAmong(array $dependencies): array
    {
        return array_values(array_filter(
            $dependencies,
            static fn(Dependency $dependency): bool => match ($dependency->type) {
                DependencyType::Extends, DependencyType::Implements, DependencyType::TraitUse, DependencyType::Attribute => true,
                default => false,
            },
        ));
    }

    /** @return list<Dependency> */
    public function dependencies(): array
    {
        return $this->dependencies;
    }

    /** @return list<ClassLikeDeclaration> */
    public function classLikeDeclarations(): array
    {
        return $this->classLikeDeclarations;
    }
}
