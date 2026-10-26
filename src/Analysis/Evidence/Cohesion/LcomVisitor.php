<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Cohesion;

use PhpParser\Node;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Nop;
use PhpParser\Node\Stmt\Return_;
use PhpParser\Node\Stmt\Trait_;
use PhpParser\NodeVisitorAbstract;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\ResettableVisitorInterface;

/**
 * Visitor for collecting method-property relationships for LCOM calculation.
 *
 * For each class, tracks:
 * - List of methods (instance and static)
 * - For each method: set of properties accessed via $this->property
 * - For each method: set of methods called via $this->method(), self:: or static::,
 *   with calls from closures, arrow functions and first-class callables kept apart
 *   as deferred calls
 * - Static method markers (excluded from LCOM graph)
 *
 * Lifecycle bodies remain available to trait aliases, so every method records its
 * deferred calls alike; the effective method population excludes constructor and
 * destructor names and interprets deferred calls after trait composition.
 *
 * Anonymous classes are ignored.
 */
final class LcomVisitor extends NodeVisitorAbstract implements ResettableVisitorInterface
{
    use ClassVisitorStackTrait;

    /** @var array<int, LcomClassData> */
    private array $classData = [];

    /**
     * Stack of method contexts (to handle methods inside anonymous classes).
     *
     * @var list<string>
     */
    private array $methodStack = [];

    /** Nesting depth of closures, arrow functions and nested functions inside the current method. */
    private int $closureDepth = 0;

    /**
     * Closure depths of the enclosing methods, restored when a nested method ends.
     *
     * @var list<int>
     */
    private array $enclosingClosureDepths = [];

    public function reset(): void
    {
        $this->classData = [];
        $this->resetClassVisitorStack();
        $this->methodStack = [];
        $this->closureDepth = 0;
        $this->enclosingClosureDepths = [];
    }

    /**
     * @return array<int, LcomClassData>
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

        // Track class-like types
        if ($this->isClassLikeNode($node)) {
            $className = $this->extractClassLikeName($node);
            $this->pushClass($className, $className === null ? null : $node->getStartFilePos());

            // Only create data for named classes
            if ($className !== null) {
                $position = $node->getStartFilePos();
                $this->classData[$position] = new LcomClassData(
                    namespace: $this->currentNamespace,
                    className: $className,
                    line: $node->getStartLine(),
                    startFilePos: $node->getStartFilePos(),
                );
            }

            return null;
        }

        // Track method — always push to methodStack (even inside anonymous classes)
        // to keep push/pop balanced with leaveNode.
        $currentClass = $this->getCurrentClass();

        if ($node instanceof Node\Stmt\TraitUse || $node instanceof Node\Stmt\Property) {
            $position = $this->getCurrentClassPosition();
            CohesionAstFacts::captureMember($node, $position === null ? null : ($this->classData[$position] ?? null));

            return null;
        }

        if ($node instanceof ClassMethod) {
            $this->captureMethod($node, $currentClass);

            return null;
        }

        $currentMethod = $this->getCurrentMethod();
        if ($currentMethod === null) {
            return null;
        }
        if ($node instanceof Node\FunctionLike) {
            ++$this->closureDepth;

            return null;
        }
        if ($currentClass === null) {
            return null;
        }

        $position = $this->getCurrentClassPosition();
        if (!isset($this->classData[$position])) {
            return null;
        }

        // Track property access via $this->property
        if ($node instanceof PropertyFetch
            && $node->var instanceof Variable
            && $node->var->name === 'this'
        ) {
            $propertyName = $this->extractPropertyName($node);
            if ($propertyName !== null) {
                $this->classData[$position]->addPropertyAccess($currentMethod, $propertyName);
            }

            return null;
        }

        if ($this->closureDepth > 0) {
            CohesionAstFacts::captureDeferredCall($node, $this->classData[$position], $currentMethod);
        } else {
            CohesionAstFacts::captureCall($node, $this->classData[$position], $currentMethod);
        }

        return null;
    }

    private function captureMethod(ClassMethod $node, ?string $currentClass): void
    {
        $methodName = $node->name->toString();
        $this->methodStack[] = $methodName;
        $this->enclosingClosureDepths[] = $this->closureDepth;
        $this->closureDepth = 0;

        $position = $this->getCurrentClassPosition();
        CohesionAstFacts::captureMethod($node, $position === null ? null : ($this->classData[$position] ?? null));

        // Lifecycle bodies can become ordinary methods through trait aliases.
        if ($node->isAbstract()) {
            return;
        }
        if ($currentClass === null || !isset($this->classData[$position])) {
            return;
        }

        $this->classData[$position]->addMethod($methodName);
        if ($node->isStatic()) {
            $this->classData[$position]->markStatic($methodName);
        }
        if ($this->isStatelessConstant($node)) {
            $this->classData[$position]->markStatelessConstant($methodName);
        }
    }

    public function leaveNode(Node $node): ?int
    {
        if ($node instanceof ClassMethod) {
            $this->closureDepth = array_pop($this->enclosingClosureDepths) ?? 0;
        } elseif ($node instanceof Node\FunctionLike && $this->methodStack !== []) {
            --$this->closureDepth;
        }
        $this->leaveClassVisitorNode($node);

        return null;
    }

    /**
     * Returns current method name or null if not inside a method.
     */
    private function getCurrentMethod(): ?string
    {
        if ($this->methodStack === []) {
            return null;
        }

        return $this->methodStack[array_key_last($this->methodStack)];
    }

    /**
     * Class and trait bodies carry facts; only classes receive LCOM in this phase.
     */
    private function isClassLikeNode(Node $node): bool
    {
        return $node instanceof Class_ || $node instanceof Trait_;
    }

    /**
     * Determines whether a method is a stateless constant.
     *
     * A stateless constant method has:
     * - A body that is empty or a single return of a constant expression
     * - No property access or instance method calls will be detected by AST traversal
     *
     * The classification is based purely on the method body shape. The actual absence
     * of property/method-call edges is verified in LcomMethodPopulation::partitionByStatelessness().
     *
     * Abstract/static methods are excluded here
     * because they are already handled separately in the LCOM graph.
     */
    private function isStatelessConstant(ClassMethod $node): bool
    {
        // Abstract methods are excluded from LCOM graph entirely
        if ($node->isAbstract()) {
            return false;
        }

        // Static methods are already excluded from LCOM, no need to classify
        if ($node->isStatic()) {
            return false;
        }

        // Empty body or null stmts — stateless
        if ($node->stmts === null || $node->stmts === []) {
            return true;
        }

        return $this->hasConstantBody($node);
    }

    /**
     * Whether a method body consists of a single return of a constant expression (or is empty after filtering Nops).
     *
     * The stateless classifier uses the body shape after graph exclusions.
     */
    private function hasConstantBody(ClassMethod $node): bool
    {
        // Filter out Nop statements (standalone comments like "// No-op")
        $stmts = array_values(array_filter($node->stmts ?? [], static fn(Node $s) => !$s instanceof Nop));

        if ($stmts === []) {
            return true;
        }

        if (\count($stmts) !== 1) {
            return false;
        }

        $stmt = $stmts[0];

        if (!$stmt instanceof Return_) {
            return false;
        }

        // return; (void return)
        if ($stmt->expr === null) {
            return true;
        }

        return $this->isConstantExpression($stmt->expr);
    }

    /**
     * Whether an expression is a constant (no instance state access).
     *
     * Recognizes: scalars, null/true/false, class constants on any literal class
     * name (self::X, static::X, parent::X, Foo::X, an imported enum case), and
     * arrays of constant expressions.
     */
    private function isConstantExpression(Node\Expr $expr): bool
    {
        // Scalar literals (int, float, string)
        if ($expr instanceof Scalar) {
            return true;
        }

        // Named constants: null, true, false, and user-defined constants (e.g., PHP_INT_MAX).
        // Intentionally accepts all ConstFetch nodes — any named constant is stateless
        // (no instance state access), so the broader match is correct for LCOM purposes.
        if ($expr instanceof ConstFetch) {
            return true;
        }

        // Class constant fetch on a literal class name AND a literal constant
        // name: self::X, static::X, parent::X, Foo::X, or an enum case
        // (Suit::Hearts parses the same way). Both operands must be resolved at
        // compile time for the fetch to read no instance state. Excludes the
        // dynamic class form ($this->obj::X, $var::X), where $expr->class is an
        // Expr, not a Name, and the dynamic constant-name form
        // (self::{$this->prop}, PHP 8.3+), where $expr->name is an Expr, not an
        // Identifier — either operand can itself read state and stays
        // unrecognized.
        if ($expr instanceof ClassConstFetch && $expr->class instanceof Name && $expr->name instanceof Identifier) {
            return true;
        }

        // Array of constant expressions (including empty array)
        if ($expr instanceof Array_) {
            foreach ($expr->items as $item) {
                if ($item === null) {
                    continue;
                }
                if ($item->key !== null && !$this->isConstantExpression($item->key)) {
                    return false;
                }
                if (!$this->isConstantExpression($item->value)) {
                    return false;
                }
            }

            return true;
        }

        return false;
    }
}
