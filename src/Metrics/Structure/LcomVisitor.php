<?php

declare(strict_types=1);

namespace Qualimetrix\Metrics\Structure;

use PhpParser\Node;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Nop;
use PhpParser\Node\Stmt\Return_;
use PhpParser\NodeVisitorAbstract;
use Qualimetrix\Metrics\ResettableVisitorInterface;

/**
 * Visitor for collecting method-property relationships for LCOM calculation.
 *
 * For each class, tracks:
 * - List of methods (instance and static)
 * - For each method: set of properties accessed via $this->property
 * - For each method: set of methods called via $this->method()
 * - Static method markers (excluded from LCOM graph)
 *
 * Anonymous classes are ignored.
 */
final class LcomVisitor extends NodeVisitorAbstract implements ResettableVisitorInterface
{
    use ClassVisitorStackTrait;

    /**
     * @var array<string, LcomClassData>
     *                                   Class FQN => LCOM data
     */
    private array $classData = [];

    /**
     * Stack of method contexts (to handle methods inside anonymous classes).
     *
     * @var list<string>
     */
    private array $methodStack = [];

    public function reset(): void
    {
        $this->classData = [];
        $this->resetClassVisitorStack();
        $this->methodStack = [];
    }

    /**
     * @return array<string, LcomClassData>
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
            $this->pushClass($className);

            // Only create data for named classes
            if ($className !== null) {
                $fqn = $this->buildClassFqn($className);
                $this->classData[$fqn] = new LcomClassData(
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
            $methodName = $node->name->toString();
            $this->methodStack[] = $methodName;

            // Abstract methods have no body and no property access, creating
            // disconnected nodes that inflate LCOM. Skip them from the graph.
            if ($node->isAbstract()) {
                return null;
            }

            // Only register with classData for named classes
            if ($currentClass !== null) {
                $fqn = $this->buildClassFqn($currentClass);
                if (isset($this->classData[$fqn])) {
                    $this->classData[$fqn]->addMethod($methodName);

                    if ($node->isStatic()) {
                        $this->classData[$fqn]->markStatic($methodName);
                    }

                    if ($this->isStatelessConstant($node)) {
                        $this->classData[$fqn]->markStatelessConstant($methodName);
                    }

                    if (!$this->isMethodTrivial($node)) {
                        $this->classData[$fqn]->markNonTrivial();
                    }
                }
            }

            return null;
        }

        $currentMethod = $this->getCurrentMethod();
        if ($currentMethod === null || $currentClass === null) {
            return null;
        }

        $fqn = $this->buildClassFqn($currentClass);
        if (!isset($this->classData[$fqn])) {
            return null;
        }

        // Track property access via $this->property
        if ($node instanceof PropertyFetch
            && $node->var instanceof Variable
            && $node->var->name === 'this'
        ) {
            $propertyName = $this->extractPropertyName($node);
            if ($propertyName !== null) {
                $this->classData[$fqn]->addPropertyAccess($currentMethod, $propertyName);
            }

            return null;
        }

        // Track method call via $this->method()
        if ($node instanceof MethodCall
            && $node->var instanceof Variable
            && $node->var->name === 'this'
            && $node->name instanceof Identifier
        ) {
            $this->classData[$fqn]->addMethodCall($currentMethod, $node->name->toString());

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
     * Checks if node is a class-like type for LCOM calculation (class only).
     *
     * Note: Traits, interfaces, and enums are intentionally excluded - LCOM is not meaningful for them.
     */
    private function isClassLikeNode(Node $node): bool
    {
        return $node instanceof Class_;
    }

    /**
     * Determines whether a method body is trivial.
     *
     * Trivial methods: empty body, or a single return of null/scalar/constant/empty array/
     * class constant (self::X, static::X).
     * Classes where ALL methods are trivial (e.g., Null Objects) get LCOM=1
     * to avoid misleadingly high LCOM values.
     */
    private function isMethodTrivial(ClassMethod $node): bool
    {
        // Abstract methods (stmts === null) are already excluded from the LCOM graph
        if ($node->stmts === null || $node->stmts === []) {
            return true;
        }

        return $this->hasConstantBody($node);
    }

    /**
     * Determines whether a method is a stateless constant.
     *
     * A stateless constant method has:
     * - A body that is empty or a single return of a constant expression
     * - No property access or instance method calls will be detected by AST traversal
     *
     * The classification is based purely on the method body shape. The actual absence
     * of property/method-call edges is verified in LcomClassData::isEffectivelyStateless().
     *
     * Note: shares the body-shape check with isMethodTrivial() via hasConstantBody(),
     * but applies different guard conditions — abstract/static methods are excluded here
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
     * Shared by isMethodTrivial() and isStatelessConstant() — both need to detect
     * the same body shape, but apply different pre-conditions (guard clauses).
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
     * Recognizes: scalars, null/true/false, class constants (self::X, static::X),
     * and arrays of constant expressions.
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

        // self::NAME, static::NAME
        if ($expr instanceof ClassConstFetch
            && $expr->class instanceof Name
            && \in_array($expr->class->toLowerString(), ['self', 'static'], true)
        ) {
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
