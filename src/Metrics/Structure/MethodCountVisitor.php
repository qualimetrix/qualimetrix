<?php

declare(strict_types=1);

namespace AiMessDetector\Metrics\Structure;

use AiMessDetector\Metrics\ResettableVisitorInterface;
use PhpParser\Node;
use PhpParser\Node\Param;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Enum_;
use PhpParser\Node\Stmt\Interface_;
use PhpParser\Node\Stmt\Property;
use PhpParser\Node\Stmt\Trait_;
use PhpParser\NodeVisitorAbstract;

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
     * @var array<string, MethodCountMetrics>
     *                                        Class FQN => metrics
     */
    private array $classMetrics = [];

    private ?string $currentNamespace = null;

    /**
     * Stack of class contexts (to handle nested/anonymous classes).
     * Each entry is the class name or null for anonymous classes.
     *
     * @var list<string|null>
     */
    private array $classStack = [];

    public function reset(): void
    {
        $this->classMetrics = [];
        $this->currentNamespace = null;
        $this->classStack = [];
    }

    /**
     * Returns current class name or null if inside anonymous class or no class.
     */
    private function getCurrentClass(): ?string
    {
        if ($this->classStack === []) {
            return null;
        }

        return $this->classStack[array_key_last($this->classStack)];
    }

    /**
     * @return array<string, MethodCountMetrics>
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
        if ($this->isClassLikeNode($node)) {
            $className = $this->extractClassLikeName($node);
            // Push to stack (null for anonymous classes)
            $this->classStack[] = $className;

            // Only create metrics for named classes
            if ($className !== null) {
                $fqn = $this->buildClassFqn($className);
                $this->classMetrics[$fqn] = new MethodCountMetrics(
                    namespace: $this->currentNamespace,
                    className: $className,
                    line: $node->getStartLine(),
                );

                // Process class characteristics and promoted properties
                if ($node instanceof Class_) {
                    // RFC-008: Collect isReadonly for false positive reduction
                    $this->classMetrics[$fqn]->isReadonly = $node->isReadonly();

                    $this->processConstructorPromotedProperties($node, $fqn);
                }
            }

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

    public function leaveNode(Node $node): ?int
    {
        // Exit class-like scope
        if ($this->isClassLikeNode($node)) {
            array_pop($this->classStack);
        }

        // Exit namespace scope
        if ($node instanceof Node\Stmt\Namespace_) {
            $this->currentNamespace = null;
        }

        return null;
    }

    private function countMethod(ClassMethod $method, string $className): void
    {
        $fqn = $this->buildClassFqn($className);

        if (!isset($this->classMetrics[$fqn])) {
            return;
        }

        $metrics = $this->classMetrics[$fqn];
        $methodName = $method->name->toString();

        // RFC-008: Track constructor presence for isDataClass calculation
        if ($methodName === '__construct') {
            $metrics->hasConstructor = true;
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
     * Check if method is a getter (get*, is*, has*).
     */
    private function isGetter(string $methodName): bool
    {
        $lower = strtolower($methodName);

        return str_starts_with($lower, 'get')
            || str_starts_with($lower, 'is')
            || str_starts_with($lower, 'has');
    }

    /**
     * Check if method is a setter (set*).
     */
    private function isSetter(string $methodName): bool
    {
        return str_starts_with(strtolower($methodName), 'set');
    }

    /**
     * Extracts class name from class-like nodes (class, interface, trait, enum).
     * Returns null for anonymous classes or non-class-like nodes.
     */
    private function extractClassLikeName(Node $node): ?string
    {
        return match (true) {
            $node instanceof Class_ && $node->name !== null => $node->name->toString(),
            $node instanceof Interface_ && $node->name !== null => $node->name->toString(),
            $node instanceof Trait_ && $node->name !== null => $node->name->toString(),
            $node instanceof Enum_ && $node->name !== null => $node->name->toString(),
            default => null,
        };
    }

    /**
     * Checks if node is a class-like type (class, interface, trait, enum).
     */
    private function isClassLikeNode(Node $node): bool
    {
        return $node instanceof Class_
            || $node instanceof Interface_
            || $node instanceof Trait_
            || $node instanceof Enum_;
    }

    private function buildClassFqn(string $className): string
    {
        if ($this->currentNamespace !== null && $this->currentNamespace !== '') {
            return $this->currentNamespace . '\\' . $className;
        }

        return $className;
    }

    /**
     * Count properties in a property declaration.
     * Note: One Property node can contain multiple properties (e.g., public $a, $b, $c).
     */
    private function countProperty(Property $property, string $className): void
    {
        $fqn = $this->buildClassFqn($className);

        if (!isset($this->classMetrics[$fqn])) {
            return;
        }

        $visibility = $this->getPropertyVisibility($property);

        // Each property declaration can have multiple properties: public $a, $b;
        $count = \count($property->props);

        for ($i = 0; $i < $count; $i++) {
            $this->classMetrics[$fqn]->addProperty($visibility);
        }
    }

    /**
     * Process promoted properties from constructor.
     */
    private function processConstructorPromotedProperties(Class_ $class, string $fqn): void
    {
        $constructor = $class->getMethod('__construct');

        if ($constructor === null) {
            return;
        }

        foreach ($constructor->params as $param) {
            if ($this->isPromotedProperty($param)) {
                $visibility = $this->getParamVisibility($param);
                $this->classMetrics[$fqn]->addProperty($visibility, isPromoted: true);
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
