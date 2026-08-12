<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\DependencyModel\Contract;

use Qualimetrix\Core\Symbol\DeclarationPath;
use Qualimetrix\Core\Symbol\LogicalClassPath;
use Qualimetrix\Core\Symbol\SymbolPath;

/**
 * Represents a single dependency from one class to another.
 *
 * A dependency captures the relationship between a source class and a target class,
 * including the type of dependency and its location in the source code.
 */
final readonly class Dependency
{
    /**
     * @param DeclarationPath $source Exact source declaration identity
     * @param LogicalClassPath $target Logical class identity of the dependency target
     * @param DependencyType $type The type of dependency relationship
     * @param DependencyLocationInterface $location Where in the source code this dependency occurs
     */
    public function __construct(
        public DeclarationPath $source,
        public LogicalClassPath $target,
        public DependencyType $type,
        public DependencyLocationInterface $location,
    ) {}

    /**
     * Returns true if this is a dependency between different namespaces.
     */
    public function isCrossNamespace(): bool
    {
        return $this->sourceLogical()->namespace !== $this->targetLogical()->namespace;
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
            $this->sourceLogical()->toString(),
            $this->type->description(),
            $this->targetLogical()->toString(),
            $this->location->toString(),
        );
    }

    /** Logical source projection used by graph and coupling consumers. */
    public function sourceLogical(): SymbolPath
    {
        return $this->source->logical;
    }

    /** Logical target projection used by graph and coupling consumers. */
    public function targetLogical(): SymbolPath
    {
        return $this->target->symbolPath;
    }
}
