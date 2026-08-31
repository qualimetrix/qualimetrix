<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Size;

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
    public bool $hasPublicConstructor = false;

    public function __construct(
        public readonly ?string $namespace = null,
        public readonly string $className = '',
        public readonly int $line = 0,
        public readonly int $startFilePos = 0,
    ) {}

    /**
     * Returns method count excluding getters and setters.
     */
    public function methodCount(): int
    {
        return $this->methodCountPublic + $this->methodCountProtected + $this->methodCountPrivate;
    }

    /**
     * Weight of Class (Lanza & Marinescu): the share of the public interface
     * that carries behaviour rather than data access, as a percentage.
     *
     * Numerator counts public methods that are neither accessors nor the
     * constructor; the denominator counts every other public member — public
     * methods (accessors included) plus public properties. Lanza & Marinescu
     * define a functional method as neither accessor nor constructor, and
     * leaving the constructor in would floor the ratio at 1/N for exactly the
     * small classes the rule targets.
     *
     * A method is an accessor by name only ({@see MethodCountVisitor}), so a
     * public method whose body merely forwards to a collaborator counts as
     * behaviour: WOC measures the shape of the interface, not the weight of
     * the bodies behind it. Only members declared by this class are counted;
     * inherited and trait-imported ones are invisible here.
     *
     * A class with no public members has no data surface to expose, so the
     * degenerate case is defined as 100 (fully functional) rather than left
     * undefined — that keeps {@see \Qualimetrix\Analysis\Evidence\Design\DataClass\DataClassRule}
     * a plain two-threshold gate.
     */
    public function woc(): int
    {
        $constructor = $this->hasPublicConstructor ? 1 : 0;
        $publicMembers = $this->methodCountPublicAll - $constructor + $this->propertyCountPublic;

        if ($publicMembers === 0) {
            return 100;
        }

        return (int) round((($this->methodCountPublic - $constructor) / $publicMembers) * 100);
    }

    /**
     * Whether the class declares nothing but accessors and a constructor.
     */
    public function isDataClass(): bool
    {
        return $this->methodCountTotal
            - $this->getterCount
            - $this->setterCount
            - (int) $this->hasConstructor === 0;
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
