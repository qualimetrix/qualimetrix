<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\CodeSmell;

use PhpParser\Node;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Param;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassConst;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Enum_;
use PhpParser\Node\Stmt\Interface_;
use PhpParser\Node\Stmt\Property;
use PhpParser\Node\Stmt\Trait_;
use PhpParser\NodeVisitorAbstract;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\ResettableVisitorInterface;

/**
 * AST visitor for detecting unused private class members.
 *
 * Tracks declarations and usages of private methods, properties, and constants
 * within each class. On leaving a class node, computes unused = declared - used.
 *
 * Handles:
 * - Instance methods via $this->method()
 * - Static methods via self::method() / static::method()
 * - Properties via $this->prop / self::$prop / static::$prop
 * - Constants via self::CONST / static::CONST
 * - Constructor promoted properties
 * - Magic method awareness (__get/__set skip properties, __call/__callStatic skip methods),
 *   whatever the visibility and letter case of the magic method
 * - Method names compared case-insensitively, as PHP resolves them
 * - A call a private method makes to itself does not count as a usage
 * - Anonymous class isolation via classStack
 * - Enums are analyzed like classes; interfaces and traits are not
 *
 * Same-file trait resolution:
 * - When a class uses a trait defined in the same file, the trait's method bodies
 *   are scanned for usages of $this->method(), $this->prop, self::CONST, etc.
 * - These usages are added to the class's used sets, preventing false positives.
 * - Recursive trait resolution is supported (trait using another trait in the same file).
 * - Cross-file trait resolution is not supported.
 *
 * Limitations:
 * - Variable method/property access ($this->$name) not detected
 * - Callable syntax [$this, 'method'] not detected
 * - Traits from other files are not resolved
 */
final class UnusedPrivateVisitor extends NodeVisitorAbstract implements ResettableVisitorInterface
{
    use UsageTrackingTrait;
    /** Lowercase, because PHP method names are case-insensitive. */
    private const MAGIC_METHODS = [
        '__construct', '__destruct', '__call', '__callstatic',
        '__get', '__set', '__isset', '__unset',
        '__sleep', '__wakeup', '__serialize', '__unserialize',
        '__tostring', '__invoke', '__debuginfo', '__clone', '__set_state',
    ];

    /**
     * @var array<string, UnusedPrivateClassData>
     */
    private array $classData = [];

    private ?string $currentNamespace = null;

    /**
     * Stack of class FQNs. Null for anonymous classes, interfaces, traits.
     *
     * @var list<string|null>
     */
    private array $classStack = [];

    /**
     * Trait definitions found in the current file, keyed by FQN.
     *
     * @var array<string, Trait_>
     */
    private array $traitDefinitions = [];

    /**
     * Callable-scoped variables proven to hold an instance created by
     * new self() or new static().
     *
     * @var list<array<string, true>>
     */
    private array $sameClassReceiverScopes = [];

    /**
     * Lowercase names of the enclosing class methods, innermost last; null for
     * methods of classes that are not tracked (anonymous classes).
     *
     * @var list<string|null>
     */
    private array $methodStack = [];

    public function reset(): void
    {
        $this->classData = [];
        $this->currentNamespace = null;
        $this->classStack = [];
        $this->traitDefinitions = [];
        $this->sameClassReceiverScopes = [];
        $this->methodStack = [];
    }

    /**
     * @return array<string, UnusedPrivateClassData>
     */
    public function getClassData(): array
    {
        return $this->classData;
    }

    /**
     * Pre-pass: collect all trait definitions in the file for same-file resolution.
     *
     * @param Node[] $nodes
     */
    public function beforeTraverse(array $nodes): ?array
    {
        $this->traitDefinitions = TraitUsageResolver::collectTraitDefinitions($nodes);

        return null;
    }

    public function enterNode(Node $node): ?int
    {
        if ($node instanceof Node\Stmt\Namespace_) {
            $this->currentNamespace = $node->name?->toString() ?? '';

            return null;
        }

        // Handle class-like nodes
        if ($this->isClassLikeNode($node)) {
            return $this->enterClassLike($node);
        }

        // Pushed for every callable, tracked or not: leaveNode() pops for every callable.
        if ($node instanceof Node\FunctionLike) {
            $this->sameClassReceiverScopes[] = [];
        }

        $currentFqn = $this->getCurrentClassFqn();
        $classData = $currentFqn === null ? null : ($this->classData[$currentFqn] ?? null);

        if ($node instanceof ClassMethod) {
            $this->methodStack[] = $classData === null ? null : $node->name->toLowerString();
        }

        if ($classData === null) {
            return null;
        }

        // Track declarations
        if ($node instanceof ClassMethod) {
            $this->trackMethodDeclaration($node, $classData);

            return null;
        }

        $this->trackSameClassReceiverAssignment($node);

        if ($node instanceof Property) {
            $this->trackPropertyDeclaration($node, $classData);

            return null;
        }

        if ($node instanceof ClassConst) {
            $this->trackConstantDeclaration($node, $classData);

            return null;
        }

        // Track constructor promoted properties
        if ($node instanceof Param && $this->isPromotedPrivateParam($node)) {
            $name = $node->var instanceof Variable && \is_string($node->var->name)
                ? $node->var->name
                : null;
            if ($name !== null) {
                $classData->declaredProperties[$name] = $node->getStartLine();
            }

            return null;
        }

        // Track usages
        $this->trackUsage(
            $node,
            $classData,
            $this->sameClassReceiverScopes === []
                ? []
                : $this->sameClassReceiverScopes[array_key_last($this->sameClassReceiverScopes)],
            $this->methodStack === [] ? null : $this->methodStack[array_key_last($this->methodStack)],
        );

        return null;
    }

    public function leaveNode(Node $node): ?int
    {
        if ($node instanceof Node\FunctionLike) {
            array_pop($this->sameClassReceiverScopes);
        }

        if ($node instanceof ClassMethod) {
            array_pop($this->methodStack);
        }

        if ($this->isClassLikeNode($node)) {
            array_pop($this->classStack);
        }

        if ($node instanceof Node\Stmt\Namespace_) {
            $this->currentNamespace = null;
        }

        return null;
    }

    private function enterClassLike(Node $node): null
    {
        // Skip interfaces and traits
        if ($node instanceof Interface_ || $node instanceof Trait_) {
            $this->classStack[] = null;

            return null;
        }

        // Skip anonymous classes
        if ($node instanceof Class_ && $node->name === null) {
            $this->classStack[] = null;

            return null;
        }

        $className = match (true) {
            $node instanceof Class_ && $node->name !== null => $node->name->toString(),
            $node instanceof Enum_ => $node->name?->toString(),
            default => null,
        };

        if ($className === null) {
            $this->classStack[] = null;

            return null;
        }

        $fqn = $this->buildFqn($className);
        $this->classStack[] = $fqn;

        $this->classData[$fqn] = new UnusedPrivateClassData(
            namespace: $this->currentNamespace,
            className: $className,
            line: $node->getStartLine(),
            startFilePos: $node->getStartFilePos(),
        );

        // Resolve same-file trait usages
        if (($node instanceof Class_ || $node instanceof Enum_) && $this->traitDefinitions !== []) {
            $resolver = new TraitUsageResolver($this->traitDefinitions, $this->currentNamespace);
            $resolver->resolve($node->stmts, $this->classData[$fqn]);
        }

        return null;
    }

    private function trackMethodDeclaration(ClassMethod $node, UnusedPrivateClassData $data): void
    {
        $lowerName = $node->name->toLowerString();
        $data->noteMethodDeclaration($lowerName);

        if (!$node->isPrivate() || \in_array($lowerName, self::MAGIC_METHODS, true)) {
            return;
        }

        $data->declaredMethods[$node->name->toString()] = $node->getStartLine();
    }

    private function trackPropertyDeclaration(Property $node, UnusedPrivateClassData $data): void
    {
        if (!$node->isPrivate()) {
            return;
        }

        foreach ($node->props as $prop) {
            $data->declaredProperties[$prop->name->toString()] = $node->getStartLine();
        }
    }

    private function trackConstantDeclaration(ClassConst $node, UnusedPrivateClassData $data): void
    {
        if (!$node->isPrivate()) {
            return;
        }

        foreach ($node->consts as $const) {
            $data->declaredConstants[$const->name->toString()] = $node->getStartLine();
        }
    }

    private function isPromotedPrivateParam(Param $node): bool
    {
        return ($node->flags & Class_::MODIFIER_PRIVATE) !== 0;
    }

    private function isClassLikeNode(Node $node): bool
    {
        return $node instanceof Class_
            || $node instanceof Interface_
            || $node instanceof Enum_
            || $node instanceof Trait_;
    }

    private function getCurrentClassFqn(): ?string
    {
        if ($this->classStack === []) {
            return null;
        }

        return $this->classStack[array_key_last($this->classStack)];
    }

    private function buildFqn(string $className): string
    {
        if ($this->currentNamespace !== null && $this->currentNamespace !== '') {
            return $this->currentNamespace . '\\' . $className;
        }

        return $className;
    }

    private function trackSameClassReceiverAssignment(Node $node): void
    {
        if (!$node instanceof Assign
            || !$node->var instanceof Variable
            || !\is_string($node->var->name)
            || $node->var->name === 'this'
            || $this->sameClassReceiverScopes === []
        ) {
            return;
        }

        $scopeIndex = array_key_last($this->sameClassReceiverScopes);
        $name = $node->var->name;
        if ($node->expr instanceof New_
            && $node->expr->class instanceof Node\Name
            && $this->isSelfOrStatic($node->expr->class)
        ) {
            $this->sameClassReceiverScopes[$scopeIndex][$name] = true;

            return;
        }

        unset($this->sameClassReceiverScopes[$scopeIndex][$name]);
    }
}
