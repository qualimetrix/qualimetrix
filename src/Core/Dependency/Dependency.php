<?php

declare(strict_types=1);

namespace Qualimetrix\Core\Dependency;

use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Core\Violation\Location;

/**
 * Represents a single dependency from one class to another.
 *
 * A dependency captures the relationship between a source class and a target class,
 * including the type of dependency and its location in the source code.
 */
final readonly class Dependency
{
    /**
     * @param SymbolPath $source Class-level SymbolPath of the class that has the dependency
     * @param SymbolPath $target Class-level SymbolPath of the class being depended upon
     * @param DependencyType $type The type of dependency relationship
     * @param Location $location Where in the source code this dependency occurs
     */
    public function __construct(
        public SymbolPath $source,
        public SymbolPath $target,
        public DependencyType $type,
        public Location $location,
    ) {}

    /**
     * Returns true if this is a dependency between different namespaces.
     */
    public function isCrossNamespace(): bool
    {
        return $this->source->namespace !== $this->target->namespace;
    }

    /**
     * Returns true if this dependency creates strong coupling.
     */
    public function isStrongCoupling(): bool
    {
        return $this->type->isStrongCoupling();
    }

    /**
     * Returns human-readable representation of this dependency.
     */
    public function toString(): string
    {
        return \sprintf(
            '%s %s %s at %s',
            $this->source->toString(),
            $this->type->description(),
            $this->target->toString(),
            $this->location->toString(),
        );
    }
}
