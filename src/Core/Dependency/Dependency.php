<?php

declare(strict_types=1);

namespace AiMessDetector\Core\Dependency;

use AiMessDetector\Core\Violation\Location;

/**
 * Represents a single dependency from one class to another.
 *
 * A dependency captures the relationship between a source class and a target class,
 * including the type of dependency and its location in the source code.
 */
final readonly class Dependency
{
    /**
     * @param string $sourceClass FQN of the class that has the dependency
     * @param string $targetClass FQN of the class being depended upon
     * @param DependencyType $type The type of dependency relationship
     * @param Location $location Where in the source code this dependency occurs
     */
    public function __construct(
        public string $sourceClass,
        public string $targetClass,
        public DependencyType $type,
        public Location $location,
    ) {}

    /**
     * Returns the namespace of the source class.
     */
    public function getSourceNamespace(): string
    {
        $pos = strrpos($this->sourceClass, '\\');

        return $pos !== false ? substr($this->sourceClass, 0, $pos) : '';
    }

    /**
     * Returns the namespace of the target class.
     */
    public function getTargetNamespace(): string
    {
        $pos = strrpos($this->targetClass, '\\');

        return $pos !== false ? substr($this->targetClass, 0, $pos) : '';
    }

    /**
     * Returns true if this is a dependency between different namespaces.
     */
    public function isCrossNamespace(): bool
    {
        return $this->getSourceNamespace() !== $this->getTargetNamespace();
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
            $this->sourceClass,
            $this->type->description(),
            $this->targetClass,
            $this->location->toString(),
        );
    }
}
