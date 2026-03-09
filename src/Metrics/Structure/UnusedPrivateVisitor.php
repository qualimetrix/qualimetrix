<?php

declare(strict_types=1);

namespace AiMessDetector\Metrics\Structure;

use AiMessDetector\Metrics\ResettableVisitorInterface;
use PhpParser\Node;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\StaticPropertyFetch;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Param;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassConst;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Enum_;
use PhpParser\Node\Stmt\Interface_;
use PhpParser\Node\Stmt\Property;
use PhpParser\Node\Stmt\Trait_;
use PhpParser\NodeVisitorAbstract;

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
 * - Magic method awareness (__get/__set skip properties, __call/__callStatic skip methods)
 * - Anonymous class isolation via classStack
 *
 * Limitations:
 * - Variable method/property access ($this->$name) not detected
 * - Callable syntax [$this, 'method'] not detected
 * - Traits are not analyzed (would require cross-file resolution)
 */
final class UnusedPrivateVisitor extends NodeVisitorAbstract implements ResettableVisitorInterface
{
    private const MAGIC_METHODS = [
        '__construct', '__destruct', '__call', '__callStatic',
        '__get', '__set', '__isset', '__unset',
        '__sleep', '__wakeup', '__serialize', '__unserialize',
        '__toString', '__invoke', '__debugInfo', '__clone', '__set_state',
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

    public function reset(): void
    {
        $this->classData = [];
        $this->currentNamespace = null;
        $this->classStack = [];
    }

    /**
     * @return array<string, UnusedPrivateClassData>
     */
    public function getClassData(): array
    {
        return $this->classData;
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

        $currentFqn = $this->getCurrentClassFqn();
        if ($currentFqn === null) {
            return null;
        }

        $classData = $this->classData[$currentFqn] ?? null;
        if ($classData === null) {
            return null;
        }

        // Track declarations
        if ($node instanceof ClassMethod) {
            $this->trackMethodDeclaration($node, $classData);

            return null;
        }

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
        $this->trackUsages($node, $classData);

        return null;
    }

    public function leaveNode(Node $node): ?int
    {
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
        );

        return null;
    }

    private function trackMethodDeclaration(ClassMethod $node, UnusedPrivateClassData $data): void
    {
        if (!$node->isPrivate()) {
            return;
        }

        $name = $node->name->toString();

        // Track magic method presence
        match ($name) {
            '__call' => $data->hasMagicCall = true,
            '__callStatic' => $data->hasMagicCallStatic = true,
            '__get' => $data->hasMagicGet = true,
            '__set' => $data->hasMagicSet = true,
            default => null,
        };

        // Never flag magic methods as unused
        if (\in_array($name, self::MAGIC_METHODS, true)) {
            return;
        }

        $data->declaredMethods[$name] = $node->getStartLine();
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

    private function trackUsages(Node $node, UnusedPrivateClassData $data): void
    {
        // $this->method()
        if ($node instanceof MethodCall
            && $node->var instanceof Variable
            && $node->var->name === 'this'
            && $node->name instanceof Identifier
        ) {
            $data->usedMethods[$node->name->toString()] = true;

            return;
        }

        // self::method() / static::method()
        if ($node instanceof StaticCall
            && $node->class instanceof Name
            && $this->isSelfOrStatic($node->class)
            && $node->name instanceof Identifier
        ) {
            $data->usedMethods[$node->name->toString()] = true;

            return;
        }

        // $this->property
        if ($node instanceof PropertyFetch
            && $node->var instanceof Variable
            && $node->var->name === 'this'
            && $node->name instanceof Identifier
        ) {
            $data->usedProperties[$node->name->toString()] = true;

            return;
        }

        // self::$property / static::$property
        if ($node instanceof StaticPropertyFetch
            && $node->class instanceof Name
            && $this->isSelfOrStatic($node->class)
            && $node->name instanceof Node\VarLikeIdentifier
        ) {
            $data->usedProperties[$node->name->toString()] = true;

            return;
        }

        // self::CONSTANT / static::CONSTANT
        if ($node instanceof ClassConstFetch
            && $node->class instanceof Name
            && $this->isSelfOrStatic($node->class)
            && $node->name instanceof Identifier
            && $node->name->toString() !== 'class'
        ) {
            $data->usedConstants[$node->name->toString()] = true;
        }
    }

    private function isPromotedPrivateParam(Param $node): bool
    {
        return ($node->flags & Class_::MODIFIER_PRIVATE) !== 0;
    }

    private function isSelfOrStatic(Name $name): bool
    {
        $lower = $name->toLowerString();

        return $lower === 'self' || $lower === 'static';
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
}
