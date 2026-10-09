<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Size;

use PhpParser\Node;
use PhpParser\Node\Param;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Interface_;
use PhpParser\Node\Stmt\Property;
use PhpParser\NodeVisitorAbstract;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\ResettableVisitorInterface;

/**
 * Visitor for counting methods and properties in classes by visibility.
 *
 * Collects metrics per class:
 * - methodCountTotal: all methods including getters/setters
 * - methodCount: methods excluding getters/setters
 * - methodCountPublic: public methods (excluding getters/setters)
 * - methodCountProtected: protected methods (excluding getters/setters)
 * - methodCountPrivate: private methods (excluding getters/setters)
 * - getterCount: getter methods (get*, is*, has*)
 * - setterCount: setter methods (set*)
 * - propertyCount: total number of properties
 * - propertyCountPublic: public properties
 * - propertyCountProtected: protected properties
 * - propertyCountPrivate: private properties
 * - promotedPropertyCount: constructor promoted properties (PHP 8+)
 *
 * Anonymous classes are ignored.
 */
final class MethodCountVisitor extends NodeVisitorAbstract implements ResettableVisitorInterface
{
    /**
     * @var array<int, MethodCountMetrics>
     */
    private array $classMetrics = [];

    private ?string $currentNamespace = null;

    /**
     * Stack of class contexts (to handle nested/anonymous classes).
     * Each entry is the class position or null for anonymous classes.
     *
     * @var list<int|null>
     */
    private array $classStack = [];

    public function reset(): void
    {
        $this->classMetrics = [];
        $this->currentNamespace = null;
        $this->classStack = [];
    }

    /**
     * Returns the current class position or null inside an anonymous class.
     */
    private function getCurrentClass(): ?int
    {
        if ($this->classStack === []) {
            return null;
        }

        return $this->classStack[array_key_last($this->classStack)];
    }

    /**
     * @return array<int, MethodCountMetrics>
     */
    public function getClassMetrics(): array
    {
        return $this->classMetrics;
    }

    public function enterNode(Node $node): ?int
    {
        // Track namespace
        if ($node instanceof Node\Stmt\Namespace_) {
            $this->currentNamespace = $node->name?->toString() ?? '';

            return null;
        }

        // Track class-like types
        if ($node instanceof ClassLike) {
            $this->enterClassLike($node);

            return null;
        }

        // Count methods (only for named classes)
        $currentClass = $this->getCurrentClass();
        if ($node instanceof ClassMethod && $currentClass !== null) {
            $this->countMethod($node, $currentClass);

            return null;
        }

        // Count properties (only for named classes)
        if ($node instanceof Property && $currentClass !== null) {
            $this->countProperty($node, $currentClass);

            return null;
        }

        return null;
    }

    private function enterClassLike(ClassLike $node): void
    {
        $className = $node->name?->toString();
        $this->classStack[] = $className === null ? null : $node->getStartFilePos();

        if ($className === null) {
            return;
        }

        $position = $node->getStartFilePos();
        $metrics = new MethodCountMetrics(
            namespace: $this->currentNamespace,
            className: $className,
            line: $node->getStartLine(),
            startFilePos: $position,
        );
        $this->classMetrics[$position] = $metrics;
        $metrics->isInterface = $node instanceof Interface_;

        if ($node instanceof Class_) {
            $metrics->isReadonly = $node->isReadonly();
            $metrics->isAbstract = $node->isAbstract();
            $this->processConstructorPromotedProperties($node, $position);
        }
    }

    public function leaveNode(Node $node): ?int
    {
        // Exit class-like scope
        if ($node instanceof ClassLike) {
            array_pop($this->classStack);
        }

        // Exit namespace scope
        if ($node instanceof Node\Stmt\Namespace_) {
            $this->currentNamespace = null;
        }

        return null;
    }

    private function countMethod(ClassMethod $method, int $position): void
    {
        if (!isset($this->classMetrics[$position])) {
            return;
        }

        $metrics = $this->classMetrics[$position];
        $methodName = $method->name->toString();

        // RFC-008: Track constructor presence for isDataClass calculation
        if ($methodName === '__construct') {
            $metrics->hasConstructor = true;

            if ($method->isPublic()) {
                $metrics->hasPublicConstructor = true;
            }
        }

        // Determine if getter or setter
        $isGetter = $this->isGetter($methodName);
        $isSetter = $this->isSetter($methodName);

        // Count getter/setter
        if ($isGetter) {
            $metrics->getterCount++;
        }
        if ($isSetter) {
            $metrics->setterCount++;
        }

        // Always count in total
        $metrics->methodCountTotal++;

        // Track all public methods (including getters/setters) for WOC
        if ($method->isPublic()) {
            $metrics->methodCountPublicAll++;
        }

        // Count by visibility (excluding getters/setters)
        if (!$isGetter && !$isSetter) {
            if ($method->isPublic()) {
                $metrics->methodCountPublic++;
            } elseif ($method->isProtected()) {
                $metrics->methodCountProtected++;
            } elseif ($method->isPrivate()) {
                $metrics->methodCountPrivate++;
            }
        }
    }

    /**
     * Check if method is a getter (get[A-Z], is[A-Z], has[A-Z], or exact match).
     *
     * Uses the original (non-lowercased) name to verify the character after the
     * prefix is uppercase, avoiding false positives like isolate(), getaway(), hasty().
     */
    private function isGetter(string $methodName): bool
    {
        return $this->matchesAccessorPrefix($methodName, ['get', 'is', 'has']);
    }

    /**
     * Check if method is a setter (set[A-Z] or exact match).
     *
     * Uses the original (non-lowercased) name to verify the character after the
     * prefix is uppercase, avoiding false positives like setup(), settle().
     */
    private function isSetter(string $methodName): bool
    {
        return $this->matchesAccessorPrefix($methodName, ['set']);
    }

    /**
     * Check if method name matches an accessor prefix pattern.
     *
     * A method is considered an accessor if:
     * - Its name exactly equals one of the prefixes (case-insensitive), OR
     * - It starts with a prefix (case-insensitive) followed by an uppercase letter.
     *
     * This avoids false positives like isolate(), setup(), getaway(), hasty().
     *
     * @param list<string> $prefixes
     */
    private function matchesAccessorPrefix(string $methodName, array $prefixes): bool
    {
        $lower = strtolower($methodName);

        foreach ($prefixes as $prefix) {
            $prefixLen = \strlen($prefix);

            if (!str_starts_with($lower, $prefix)) {
                continue;
            }

            // Exact match (e.g., "get", "set", "is", "has")
            if (\strlen($methodName) === $prefixLen) {
                return true;
            }

            // Prefix followed by an uppercase letter (checked on original name)
            if (ctype_upper($methodName[$prefixLen])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Count properties in a property declaration.
     * Note: One Property node can contain multiple properties (e.g., public $a, $b, $c).
     */
    private function countProperty(Property $property, int $position): void
    {
        if (!isset($this->classMetrics[$position])) {
            return;
        }

        $visibility = $this->getPropertyVisibility($property);

        // Each property declaration can have multiple properties: public $a, $b;
        $count = \count($property->props);

        for ($i = 0; $i < $count; $i++) {
            $this->classMetrics[$position]->addProperty($visibility);
        }
    }

    /**
     * Process promoted properties from constructor.
     */
    private function processConstructorPromotedProperties(Class_ $class, int $position): void
    {
        $constructor = $class->getMethod('__construct');

        if ($constructor === null) {
            return;
        }

        foreach ($constructor->params as $param) {
            if ($this->isPromotedProperty($param)) {
                $visibility = $this->getParamVisibility($param);
                $this->classMetrics[$position]->addProperty($visibility, isPromoted: true);
            }
        }
    }

    /**
     * Check if parameter is a promoted property.
     */
    private function isPromotedProperty(Param $param): bool
    {
        return $param->flags !== 0; // Has visibility modifier
    }

    /**
     * Get visibility from parameter flags.
     */
    private function getParamVisibility(Param $param): int
    {
        if (($param->flags & Class_::MODIFIER_PUBLIC) !== 0) {
            return Class_::MODIFIER_PUBLIC;
        }
        if (($param->flags & Class_::MODIFIER_PROTECTED) !== 0) {
            return Class_::MODIFIER_PROTECTED;
        }
        if (($param->flags & Class_::MODIFIER_PRIVATE) !== 0) {
            return Class_::MODIFIER_PRIVATE;
        }

        return Class_::MODIFIER_PUBLIC; // default
    }

    /**
     * Get visibility from property.
     */
    private function getPropertyVisibility(Property $property): int
    {
        if ($property->isPublic()) {
            return Class_::MODIFIER_PUBLIC;
        }
        if ($property->isProtected()) {
            return Class_::MODIFIER_PROTECTED;
        }
        if ($property->isPrivate()) {
            return Class_::MODIFIER_PRIVATE;
        }

        return Class_::MODIFIER_PUBLIC; // default
    }

}
