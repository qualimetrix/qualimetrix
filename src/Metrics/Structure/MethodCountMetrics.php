<?php

declare(strict_types=1);

namespace Qualimetrix\Metrics\Structure;

/**
 * Value object holding method count and property count metrics for a class.
 *
 * Note: This is mutable during collection phase, but treated as immutable after.
 */
final class MethodCountMetrics
{
    public int $methodCountTotal = 0;
    public int $methodCountPublic = 0;
    public int $methodCountProtected = 0;
    public int $methodCountPrivate = 0;
    public int $getterCount = 0;
    public int $setterCount = 0;

    /**
     * Total public methods INCLUDING getters/setters.
     * Used for WOC (Weight of Class) calculation.
     */
    public int $methodCountPublicAll = 0;

    // Property count metrics
    public int $propertyCount = 0;
    public int $propertyCountPublic = 0;
    public int $propertyCountProtected = 0;
    public int $propertyCountPrivate = 0;
    public int $promotedPropertyCount = 0;

    // Class characteristics for false positive reduction (RFC-008)
    public bool $isReadonly = false;
    public bool $isAbstract = false;
    public bool $isInterface = false;
    public bool $isException = false;
    public bool $hasConstructor = false;

    public function __construct(
        public readonly ?string $namespace = null,
        public readonly string $className = '',
        public readonly int $line = 0,
    ) {}

    /**
     * Returns method count excluding getters and setters.
     */
    public function methodCount(): int
    {
        return $this->methodCountPublic + $this->methodCountProtected + $this->methodCountPrivate;
    }

    /**
     * Add a property to the metrics.
     */
    public function addProperty(int $visibility, bool $isPromoted = false): void
    {
        $this->propertyCount++;

        match ($visibility) {
            \PhpParser\Node\Stmt\Class_::MODIFIER_PUBLIC => $this->propertyCountPublic++,
            \PhpParser\Node\Stmt\Class_::MODIFIER_PROTECTED => $this->propertyCountProtected++,
            \PhpParser\Node\Stmt\Class_::MODIFIER_PRIVATE => $this->propertyCountPrivate++,
            default => $this->propertyCountPublic++,
        };

        if ($isPromoted) {
            $this->promotedPropertyCount++;
        }
    }
}
