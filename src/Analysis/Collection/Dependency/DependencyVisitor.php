<?php

declare(strict_types=1);

namespace AiMessDetector\Analysis\Collection\Dependency;

use AiMessDetector\Core\Dependency\Dependency;
use AiMessDetector\Core\Dependency\DependencyType;
use AiMessDetector\Core\Violation\Location;
use PhpParser\Node;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\Instanceof_;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\StaticPropertyFetch;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\Catch_;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Enum_;
use PhpParser\Node\Stmt\GroupUse;
use PhpParser\Node\Stmt\Interface_;
use PhpParser\Node\Stmt\Namespace_;
use PhpParser\Node\Stmt\Property;
use PhpParser\Node\Stmt\Trait_;
use PhpParser\Node\Stmt\TraitUse;
use PhpParser\Node\Stmt\Use_;
use PhpParser\NodeVisitorAbstract;

/**
 * Visitor that collects all class dependencies from AST.
 *
 * Detects all 14 dependency types:
 * - Extends, Implements, TraitUse
 * - New, StaticCall, StaticPropertyFetch, ClassConstFetch
 * - TypeHint (params, returns, properties)
 * - Catch, Instanceof
 * - Attribute
 * - PropertyType
 * - IntersectionType, UnionType
 */
final class DependencyVisitor extends NodeVisitorAbstract
{
    private DependencyResolver $resolver;

    private string $file = '';
    private ?string $currentClass = null;

    /** @var array<Dependency> */
    private array $dependencies = [];

    public function __construct(DependencyResolver $resolver)
    {
        $this->resolver = $resolver;
    }

    /**
     * Initializes the visitor for a new file.
     */
    public function setFile(string $file): void
    {
        $this->file = $file;
        $this->reset();
    }

    /**
     * Resets the visitor state between files.
     *
     * Called automatically by setFile(), but can also be called directly
     * when reusing the visitor for multiple files in the same traverser.
     */
    public function reset(): void
    {
        $this->dependencies = [];
        $this->currentClass = null;
        $this->resolver->reset();
    }

    /**
     * Returns all collected dependencies.
     *
     * @return array<Dependency>
     */
    public function getDependencies(): array
    {
        return $this->dependencies;
    }

    public function enterNode(Node $node): ?int
    {
        // Track namespace
        if ($node instanceof Namespace_) {
            $this->resolver->setNamespace($node->name?->toString());

            return null;
        }

        // Collect use statements
        if ($node instanceof Use_) {
            $this->resolver->addUseStatement($node);

            return null;
        }

        if ($node instanceof GroupUse) {
            $this->resolver->addGroupUseStatement($node);

            return null;
        }

        // Track current class
        $className = $this->extractClassLikeName($node);
        if ($className !== null) {
            $this->currentClass = $this->resolver->getNamespace() !== null
                ? $this->resolver->getNamespace() . '\\' . $className
                : $className;

            // Process class inheritance and interfaces
            $this->processClassLike($node);

            return null;
        }

        // Skip if not in a class
        if ($this->currentClass === null) {
            return null;
        }

        // Process various dependency types
        $this->processNode($node);

        return null;
    }

    public function leaveNode(Node $node): ?int
    {
        if ($this->isClassLikeNode($node)) {
            $this->currentClass = null;
        }

        return null;
    }

    /**
     * Processes class-like declarations for extends/implements/trait use.
     */
    private function processClassLike(Node $node): void
    {
        if ($node instanceof Class_) {
            // Extends
            if ($node->extends !== null) {
                $this->addDependency(
                    $this->resolver->resolve($node->extends),
                    DependencyType::Extends,
                    $node->extends->getStartLine(),
                );
            }

            // Implements
            foreach ($node->implements as $interface) {
                $this->addDependency(
                    $this->resolver->resolve($interface),
                    DependencyType::Implements,
                    $interface->getStartLine(),
                );
            }

            // Attributes
            $this->processAttributes($node->attrGroups, $node->getStartLine());
        }

        if ($node instanceof Interface_) {
            // Extends (interfaces can extend multiple interfaces)
            foreach ($node->extends as $parent) {
                $this->addDependency(
                    $this->resolver->resolve($parent),
                    DependencyType::Extends,
                    $parent->getStartLine(),
                );
            }

            // Attributes
            $this->processAttributes($node->attrGroups, $node->getStartLine());
        }

        if ($node instanceof Trait_) {
            // Attributes
            $this->processAttributes($node->attrGroups, $node->getStartLine());
        }

        if ($node instanceof Enum_) {
            // Implements
            foreach ($node->implements as $interface) {
                $this->addDependency(
                    $this->resolver->resolve($interface),
                    DependencyType::Implements,
                    $interface->getStartLine(),
                );
            }

            // Attributes
            $this->processAttributes($node->attrGroups, $node->getStartLine());
        }
    }

    /**
     * Processes various node types for dependencies.
     */
    private function processNode(Node $node): void
    {
        // Trait use
        if ($node instanceof TraitUse) {
            foreach ($node->traits as $trait) {
                $this->addDependency(
                    $this->resolver->resolve($trait),
                    DependencyType::TraitUse,
                    $trait->getStartLine(),
                );
            }

            return;
        }

        // New expression
        if ($node instanceof New_) {
            if ($node->class instanceof Name) {
                $this->addDependency(
                    $this->resolver->resolve($node->class),
                    DependencyType::New_,
                    $node->getStartLine(),
                );
            }

            return;
        }

        // Static call
        if ($node instanceof StaticCall) {
            if ($node->class instanceof Name && !$this->isSelfOrParent($node->class->toString())) {
                $this->addDependency(
                    $this->resolver->resolve($node->class),
                    DependencyType::StaticCall,
                    $node->getStartLine(),
                );
            }

            return;
        }

        // Static property fetch
        if ($node instanceof StaticPropertyFetch) {
            if ($node->class instanceof Name && !$this->isSelfOrParent($node->class->toString())) {
                $this->addDependency(
                    $this->resolver->resolve($node->class),
                    DependencyType::StaticPropertyFetch,
                    $node->getStartLine(),
                );
            }

            return;
        }

        // Class constant fetch
        if ($node instanceof ClassConstFetch) {
            if ($node->class instanceof Name && !$this->isSelfOrParent($node->class->toString())) {
                $this->addDependency(
                    $this->resolver->resolve($node->class),
                    DependencyType::ClassConstFetch,
                    $node->getStartLine(),
                );
            }

            return;
        }

        // Catch
        if ($node instanceof Catch_) {
            foreach ($node->types as $type) {
                $this->addDependency(
                    $this->resolver->resolve($type),
                    DependencyType::Catch_,
                    $type->getStartLine(),
                );
            }

            return;
        }

        // Instanceof
        if ($node instanceof Instanceof_) {
            if ($node->class instanceof Name) {
                $this->addDependency(
                    $this->resolver->resolve($node->class),
                    DependencyType::Instanceof_,
                    $node->getStartLine(),
                );
            }

            return;
        }

        // Property
        if ($node instanceof Property) {
            if ($node->type !== null) {
                $this->processType($node->type, DependencyType::PropertyType);
            }
            $this->processAttributes($node->attrGroups, $node->getStartLine());

            return;
        }

        // Method
        if ($node instanceof ClassMethod) {
            $this->processMethod($node);

            return;
        }
    }

    /**
     * Processes method for parameter types, return types, and attributes.
     */
    private function processMethod(ClassMethod $method): void
    {
        // Attributes on method
        $this->processAttributes($method->attrGroups, $method->getStartLine());

        // Parameter types and attributes
        foreach ($method->params as $param) {
            if ($param->type !== null) {
                $this->processType($param->type, DependencyType::TypeHint);
            }
            $this->processAttributes($param->attrGroups, $param->getStartLine());
        }

        // Return type
        if ($method->returnType !== null) {
            $this->processType($method->returnType, DependencyType::TypeHint);
        }
    }

    /**
     * Processes type declarations (simple, union, intersection, nullable).
     */
    private function processType(Node $type, DependencyType $dependencyType): void
    {
        if ($type instanceof Name) {
            $resolved = $this->resolver->resolve($type);
            if (!$this->isBuiltinType($resolved)) {
                $this->addDependency($resolved, $dependencyType, $type->getStartLine());
            }

            return;
        }

        // Nullable type (?Foo)
        if ($type instanceof Node\NullableType) {
            $this->processType($type->type, $dependencyType);

            return;
        }

        // Union type (Foo|Bar)
        if ($type instanceof Node\UnionType) {
            foreach ($type->types as $subType) {
                $this->processType($subType, DependencyType::UnionType);
            }

            return;
        }

        // Intersection type (Foo&Bar)
        if ($type instanceof Node\IntersectionType) {
            foreach ($type->types as $subType) {
                $this->processType($subType, DependencyType::IntersectionType);
            }

            return;
        }
    }

    /**
     * Processes attributes.
     *
     * @param array<Node\AttributeGroup> $attrGroups
     */
    private function processAttributes(array $attrGroups, int $fallbackLine): void
    {
        foreach ($attrGroups as $attrGroup) {
            foreach ($attrGroup->attrs as $attr) {
                $this->addDependency(
                    $this->resolver->resolve($attr->name),
                    DependencyType::Attribute,
                    $attr->getStartLine() ?: $fallbackLine,
                );
            }
        }
    }

    /**
     * Adds a dependency to the collection.
     */
    private function addDependency(string $targetClass, DependencyType $type, int $line): void
    {
        if ($this->currentClass === null) {
            return;
        }

        // Skip self-references
        if ($targetClass === $this->currentClass) {
            return;
        }

        $this->dependencies[] = new Dependency(
            $this->currentClass,
            $targetClass,
            $type,
            new Location($this->file, $line),
        );
    }

    /**
     * Checks if a type name is a built-in type.
     */
    private function isBuiltinType(string $name): bool
    {
        return \in_array(strtolower($name), [
            'int', 'integer', 'float', 'double', 'string', 'bool', 'boolean',
            'array', 'object', 'callable', 'iterable', 'void', 'null', 'never',
            'mixed', 'true', 'false', 'self', 'static', 'parent',
        ], true);
    }

    /**
     * Checks if a name is self, static, or parent.
     */
    private function isSelfOrParent(string $name): bool
    {
        return \in_array(strtolower($name), ['self', 'static', 'parent'], true);
    }

    /**
     * Extracts class name from class-like nodes.
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
     * Checks if node is a class-like type.
     */
    private function isClassLikeNode(Node $node): bool
    {
        return $node instanceof Class_
            || $node instanceof Interface_
            || $node instanceof Trait_
            || $node instanceof Enum_;
    }
}
