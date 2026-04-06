<?php

declare(strict_types=1);

namespace Qualimetrix\Metrics\Structure;

use PhpParser\Node;
use PhpParser\Node\Param;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Enum_;
use PhpParser\Node\Stmt\GroupUse;
use PhpParser\Node\Stmt\Interface_;
use PhpParser\Node\Stmt\Property;
use PhpParser\Node\Stmt\Trait_;
use PhpParser\Node\Stmt\Use_;
use PhpParser\NodeVisitorAbstract;
use Qualimetrix\Metrics\ResettableVisitorInterface;

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
     * Exception base classes in PHP.
     * Any class extending one of these is considered an exception class.
     */
    private const array EXCEPTION_BASE_CLASSES = [
        'Exception',
        'Error',
        'RuntimeException',
        'LogicException',
        'InvalidArgumentException',
        'BadMethodCallException',
        'BadFunctionCallException',
        'DomainException',
        'LengthException',
        'OutOfRangeException',
        'OverflowException',
        'RangeException',
        'UnderflowException',
        'UnexpectedValueException',
        'OutOfBoundsException',
        'TypeError',
        'ValueError',
        'ArithmeticError',
        'DivisionByZeroError',
        'ParseError',
        'FiberError',
    ];

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

    /**
     * Map of alias/short name => FQN from `use` statements in the current namespace.
     *
     * @var array<string, string>
     */
    private array $useImports = [];

    public function reset(): void
    {
        $this->classMetrics = [];
        $this->currentNamespace = null;
        $this->classStack = [];
        $this->useImports = [];
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
            $this->useImports = [];

            return null;
        }

        // Track use imports for parent class resolution
        if ($node instanceof Use_ && $node->type === Use_::TYPE_NORMAL) {
            foreach ($node->uses as $use) {
                $alias = $use->getAlias()->toString();
                $fqn = $use->name->toString();
                $this->useImports[$alias] = $fqn;
            }

            return null;
        }

        if ($node instanceof GroupUse && $node->type === Use_::TYPE_NORMAL) {
            $prefix = $node->prefix->toString();

            foreach ($node->uses as $use) {
                if ($use->type === Use_::TYPE_NORMAL || $use->type === Use_::TYPE_UNKNOWN) {
                    $alias = $use->getAlias()->toString();
                    $fqn = $prefix . '\\' . $use->name->toString();
                    $this->useImports[$alias] = $fqn;
                }
            }

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

                // Track interface flag
                if ($node instanceof Interface_) {
                    $this->classMetrics[$fqn]->isInterface = true;
                }

                // Process class characteristics and promoted properties
                if ($node instanceof Class_) {
                    // RFC-008: Collect isReadonly for false positive reduction
                    $this->classMetrics[$fqn]->isReadonly = $node->isReadonly();
                    $this->classMetrics[$fqn]->isAbstract = $node->isAbstract();
                    $this->classMetrics[$fqn]->isException = $this->isExceptionClass($node);

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
            $this->useImports = [];
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

    /**
     * Check if a class extends a known exception base class.
     *
     * Resolves the parent class name via use imports and checks against
     * the list of standard PHP exception/error classes.
     */
    private function isExceptionClass(Class_ $node): bool
    {
        if ($node->extends === null) {
            return false;
        }

        $parentFqn = $this->resolveClassName($node->extends);

        // Strip leading backslash for comparison
        $parentFqn = ltrim($parentFqn, '\\');

        // Check the short name (last segment) against known exception base classes.
        // This catches both direct extends (\Exception) and project-specific exceptions
        // that extend framework exceptions (e.g., App\Exception\BaseException extends \RuntimeException).
        $lastBackslash = strrpos($parentFqn, '\\');
        $shortName = $lastBackslash !== false
            ? substr($parentFqn, $lastBackslash + 1)
            : $parentFqn;

        return \in_array($shortName, self::EXCEPTION_BASE_CLASSES, true);
    }

    /**
     * Resolve class name to FQN using use imports.
     */
    private function resolveClassName(Node\Name $name): string
    {
        if ($name->isFullyQualified()) {
            return $name->toString();
        }

        $className = $name->toString();

        // Check use imports: for "Foo\Bar", the first part "Foo" might be an alias
        $parts = explode('\\', $className);
        $firstPart = $parts[0];

        if (isset($this->useImports[$firstPart])) {
            if (\count($parts) === 1) {
                return $this->useImports[$firstPart];
            }

            // Replace alias with full path
            $parts[0] = $this->useImports[$firstPart];

            return implode('\\', $parts);
        }

        // Prepend current namespace
        if ($this->currentNamespace !== null && $this->currentNamespace !== '') {
            return $this->currentNamespace . '\\' . $className;
        }

        return $className;
    }
}
