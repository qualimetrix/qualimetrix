<?php

declare(strict_types=1);

namespace Qualimetrix\Metrics\Structure;

use PhpParser\Node;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
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
     * Trivial methods: empty body, or a single return of null/scalar/constant/empty array.
     * Classes where ALL methods are trivial (e.g., Null Objects) get LCOM=1
     * to avoid misleadingly high LCOM values.
     */
    private function isMethodTrivial(ClassMethod $node): bool
    {
        // Abstract methods (stmts === null) are already excluded from the LCOM graph
        if ($node->stmts === null || $node->stmts === []) {
            return true;
        }

        // Filter out Nop statements (standalone comments like "// No-op")
        $stmts = array_values(array_filter($node->stmts, static fn(Node $s) => !$s instanceof Nop));

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

        // return <scalar>, return null/true/false, return []
        return $stmt->expr instanceof Scalar
            || $stmt->expr instanceof ConstFetch
            || ($stmt->expr instanceof Array_ && $stmt->expr->items === []);
    }
}
