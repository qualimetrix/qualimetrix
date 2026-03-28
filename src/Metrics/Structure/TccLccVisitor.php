<?php

declare(strict_types=1);

namespace Qualimetrix\Metrics\Structure;

use PhpParser\Node;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Enum_;
use PhpParser\Node\Stmt\Interface_;
use PhpParser\Node\Stmt\Property;
use PhpParser\NodeVisitorAbstract;
use Qualimetrix\Metrics\ResettableVisitorInterface;

/**
 * Visitor for collecting method-property relationships for TCC/LCC calculation.
 *
 * TCC/LCC only considers PUBLIC methods (unlike LCOM which considers all methods).
 * For each class, tracks:
 * - List of public methods
 * - For each method: set of properties accessed via $this->property
 *
 * Simplified variant of Bieman & Kang (1995): measures cohesion through direct
 * property access in public methods only. Does NOT follow invocation trees
 * (a public method delegating to a private helper that accesses a property
 * is NOT counted as using that property). This is consistent with most
 * industry tools (PHPMD, PHPMetrics) but may underestimate cohesion for
 * classes that heavily use delegation patterns.
 *
 * Constructors and destructors are excluded per the B&K specification.
 * Anonymous classes and enums are ignored.
 */
final class TccLccVisitor extends NodeVisitorAbstract implements ResettableVisitorInterface
{
    use ClassVisitorStackTrait;

    /**
     * @var array<string, TccLccClassData>
     *                                     Class FQN => TCC/LCC data
     */
    private array $classData = [];

    /**
     * Stack of method contexts (to handle methods inside anonymous classes).
     * Null entries represent non-tracked methods (private, protected, abstract, static).
     *
     * @var list<string|null>
     */
    private array $methodStack = [];

    public function reset(): void
    {
        $this->classData = [];
        $this->resetClassVisitorStack();
        $this->methodStack = [];
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
            $this->handleNamespaceEnter($node);

            return null;
        }

        // Track class-like types (skip interfaces — they have no method bodies,
        // so cohesion metrics are meaningless for them)
        if ($this->isClassLikeNode($node)) {
            // Skip Interface_ entirely — no method implementations, TCC/LCC not applicable.
            // Skip Enum_ — enums cannot have instance properties, so TCC will always
            // be 0.0, which is misleading. Consistent with LCOM exclusion.
            if ($node instanceof Interface_ || $node instanceof Enum_) {
                $this->pushClass(null);

                return null;
            }

            $className = $this->extractClassLikeName($node);
            $this->pushClass($className);

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

        // Track method — always push to methodStack (even inside anonymous classes)
        // to keep push/pop balanced with leaveNode.
        $currentClass = $this->getCurrentClass();
        if ($node instanceof ClassMethod) {
            // Count promoted constructor properties (PHP 8+): parameters with
            // visibility modifiers are real instance properties.
            if ($currentClass !== null && $node->name->toString() === '__construct') {
                $fqn = $this->buildClassFqn($currentClass);
                if (isset($this->classData[$fqn])) {
                    foreach ($node->params as $param) {
                        if ($param->flags !== 0) {
                            $this->classData[$fqn]->incrementPropertyCount();
                        }
                    }
                }
            }

            // TCC/LCC only considers public, non-abstract methods of named classes.
            // Non-tracked methods push null to keep the stack balanced.
            if ($currentClass !== null
                && $node->isPublic()
                && !$node->isAbstract()
                && !$node->isStatic()
                && !\in_array($node->name->toString(), ['__construct', '__destruct'], true)
            ) {
                $methodName = $node->name->toString();
                $this->methodStack[] = $methodName;

                $fqn = $this->buildClassFqn($currentClass);
                if (isset($this->classData[$fqn])) {
                    $this->classData[$fqn]->addMethod($methodName);
                }
            } else {
                $this->methodStack[] = null;
            }

            return null;
        }

        // Track declared instance property declarations (not static) to detect
        // property-less classes where TCC is structurally undefined.
        if ($node instanceof Property && !$node->isStatic()) {
            $currentClass = $this->getCurrentClass();
            if ($currentClass !== null) {
                $fqn = $this->buildClassFqn($currentClass);
                if (isset($this->classData[$fqn])) {
                    // A single Property node can declare multiple variables: public int $a, $b;
                    $this->classData[$fqn]->incrementPropertyCount(\count($node->props));
                }
            }

            return null;
        }

        // Track property access via $this->property
        $currentMethod = $this->getCurrentMethod();
        if ($node instanceof PropertyFetch && $currentMethod !== null) {
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
                        $this->classData[$fqn]->addPropertyAccess($currentMethod, $propertyName);
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
            array_pop($this->methodStack);
        }

        // Exit class-like scope
        if ($this->isClassLikeNode($node)) {
            $this->popClass();
        }

        // Exit namespace scope
        if ($node instanceof Node\Stmt\Namespace_) {
            $this->handleNamespaceLeave();
        }

        return null;
    }

    /**
     * Returns current method name or null if not inside a tracked method.
     */
    private function getCurrentMethod(): ?string
    {
        if ($this->methodStack === []) {
            return null;
        }

        return $this->methodStack[array_key_last($this->methodStack)];
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
}
