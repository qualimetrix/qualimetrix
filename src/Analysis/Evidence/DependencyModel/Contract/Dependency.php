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
     * @param bool $describesNestedAnonymousClass True when this edge is a declaration fact
     *                                            (extends/implements/attribute/trait_use) of an anonymous class nested inside
     *                                            `$source`, not of `$source` itself — the visitor has no other place to attach
     *                                            it, since an anonymous class has no declaration identity of its own. Declaration
     *                                            readers (DIT, NOC, layer membership) must skip a flagged edge; dependency readers
     *                                            (coupling, ClassRank, cycles, violation checks, graph export) read it as-is,
     *                                            because it is still the only recorded evidence of the underlying dependency.
     * @param bool $interfaceExtends True on an {@see DependencyType::Extends} edge an interface declares
     *                               (`interface I extends J`): its target is an interface the source has, where an
     *                               unflagged `Extends` names a parent class. The graph carries no declaration kind
     *                               otherwise, and a reader asking what a declaration implements has to tell the two
     *                               apart; DIT, NOC and coupling read both alike.
     */
    private function __construct(
        public DeclarationPath $source,
        private LogicalClassPath $logicalSource,
        public LogicalClassPath $target,
        public DependencyType $type,
        public DependencyLocationInterface $location,
        public ?TypeShape $shape,
        public ?AttributeSite $attributeSite,
        public bool $describesNestedAnonymousClass,
        public bool $interfaceExtends,
    ) {}

    public static function ofKind(
        DeclarationPath $source,
        LogicalClassPath $target,
        DependencyType $type,
        DependencyLocationInterface $location,
    ): self {
        return new self($source, new LogicalClassPath($source->logical), $target, $type, $location, null, null, false, false);
    }

    public static function ofType(
        DeclarationPath $source,
        LogicalClassPath $target,
        DependencyType $position,
        DependencyLocationInterface $location,
        TypeShape $shape,
    ): self {
        return new self($source, new LogicalClassPath($source->logical), $target, $position, $location, $shape, null, false, false);
    }

    /** @qmx-ignore code-smell.boolean-argument -- The boolean records observed nested-declaration provenance on an immutable edge. */
    public static function ofAttribute(
        DeclarationPath $source,
        LogicalClassPath $target,
        DependencyLocationInterface $location,
        AttributeSite $site,
        bool $describesNestedAnonymousClass,
    ): self {
        return new self(
            $source,
            new LogicalClassPath($source->logical),
            $target,
            DependencyType::Attribute,
            $location,
            null,
            $site,
            $describesNestedAnonymousClass,
            false,
        );
    }

    /** @qmx-ignore code-smell.boolean-argument -- Both booleans preserve independent observed declaration facts on an immutable edge. */
    public static function ofClassLike(
        DeclarationPath $source,
        LogicalClassPath $target,
        DependencyType $type,
        DependencyLocationInterface $location,
        bool $describesNestedAnonymousClass,
        bool $interfaceExtends,
    ): self {
        return new self(
            $source,
            new LogicalClassPath($source->logical),
            $target,
            $type,
            $location,
            null,
            null,
            $describesNestedAnonymousClass,
            $interfaceExtends,
        );
    }

    public function withLogicalEndpoints(LogicalClassPath $source, LogicalClassPath $target): self
    {
        return new self(
            $this->source,
            $source,
            $target,
            $this->type,
            $this->location,
            $this->shape,
            $this->attributeSite,
            $this->describesNestedAnonymousClass,
            $this->interfaceExtends,
        );
    }

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
        return $this->logicalSource->symbolPath;
    }

    /** Logical target projection used by graph and coupling consumers. */
    public function targetLogical(): SymbolPath
    {
        return $this->target->symbolPath;
    }
}
