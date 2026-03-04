<?php

declare(strict_types=1);

namespace AiMessDetector\Metrics\Structure;

use AiMessDetector\Metrics\ResettableVisitorInterface;
use PhpParser\Node;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Enum_;
use PhpParser\Node\Stmt\Interface_;
use PhpParser\NodeVisitorAbstract;

/**
 * Visitor for collecting method-property relationships for TCC/LCC calculation.
 *
 * TCC/LCC only considers PUBLIC methods (unlike LCOM which considers all methods).
 * For each class, tracks:
 * - List of public methods
 * - For each method: set of properties accessed via $this->property
 *
 * Anonymous classes are ignored.
 */
final class TccLccVisitor extends NodeVisitorAbstract implements ResettableVisitorInterface
{
    /**
     * @var array<string, TccLccClassData>
     *                                     Class FQN => TCC/LCC data
     */
    private array $classData = [];

    private ?string $currentNamespace = null;

    /**
     * Stack of class contexts (to handle nested/anonymous classes).
     *
     * @var list<string|null>
     */
    private array $classStack = [];

    private ?string $currentMethod = null;

    public function reset(): void
    {
        $this->classData = [];
        $this->currentNamespace = null;
        $this->classStack = [];
        $this->currentMethod = null;
    }

    /**
     * @return array<string, TccLccClassData>
     */
    public function getClassData(): array
    {
        return $this->classData;
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
            $this->classStack[] = $className;

            // Only create data for named classes
            if ($className !== null) {
                $fqn = $this->buildClassFqn($className);
                $this->classData[$fqn] = new TccLccClassData(
                    namespace: $this->currentNamespace,
                    className: $className,
                    line: $node->getStartLine(),
                );
            }

            return null;
        }

        // Track method - ONLY PUBLIC methods for TCC/LCC
        $currentClass = $this->getCurrentClass();
        if ($node instanceof ClassMethod && $currentClass !== null) {
            // TCC/LCC only considers public, non-abstract methods
            if ($node->isPublic() && !$node->isAbstract()) {
                $methodName = $node->name->toString();
                $this->currentMethod = $methodName;

                $fqn = $this->buildClassFqn($currentClass);
                if (isset($this->classData[$fqn])) {
                    $this->classData[$fqn]->addMethod($methodName);
                }
            }

            return null;
        }

        // Track property access via $this->property
        if ($node instanceof PropertyFetch && $this->currentMethod !== null) {
            $currentClass = $this->getCurrentClass();
            if ($currentClass === null) {
                return null;
            }

            // Check if it's $this->property
            if ($node->var instanceof Variable && $node->var->name === 'this') {
                $propertyName = $this->extractPropertyName($node);
                if ($propertyName !== null) {
                    $fqn = $this->buildClassFqn($currentClass);
                    if (isset($this->classData[$fqn])) {
                        $this->classData[$fqn]->addPropertyAccess($this->currentMethod, $propertyName);
                    }
                }
            }

            return null;
        }

        return null;
    }

    public function leaveNode(Node $node): ?int
    {
        // Exit method scope
        if ($node instanceof ClassMethod) {
            $this->currentMethod = null;
        }

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
     * Extract property name from PropertyFetch node.
     */
    private function extractPropertyName(PropertyFetch $node): ?string
    {
        if ($node->name instanceof Node\Identifier) {
            return $node->name->toString();
        }

        // Dynamic property access like $this->$var - skip
        return null;
    }

    /**
     * Extracts class name from class-like nodes (class, interface, enum).
     * Returns null for anonymous classes or non-class-like nodes.
     */
    private function extractClassLikeName(Node $node): ?string
    {
        return match (true) {
            $node instanceof Class_ && $node->name !== null => $node->name->toString(),
            $node instanceof Interface_ && $node->name !== null => $node->name->toString(),
            $node instanceof Enum_ && $node->name !== null => $node->name->toString(),
            default => null,
        };
    }

    /**
     * Checks if node is a class-like type for TCC/LCC calculation.
     */
    private function isClassLikeNode(Node $node): bool
    {
        return $node instanceof Class_
            || $node instanceof Interface_
            || $node instanceof Enum_;
    }

    private function buildClassFqn(string $className): string
    {
        if ($this->currentNamespace !== null && $this->currentNamespace !== '') {
            return $this->currentNamespace . '\\' . $className;
        }

        return $className;
    }
}
