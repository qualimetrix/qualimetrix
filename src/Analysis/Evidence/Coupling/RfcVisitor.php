<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Coupling;

use PhpParser\Node;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\PropertyHook;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Enum_;
use PhpParser\Node\Stmt\Interface_;
use PhpParser\Node\Stmt\Property;
use PhpParser\Node\Stmt\Trait_;
use PhpParser\NodeVisitorAbstract;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\ResettableVisitorInterface;

/**
 * Visitor for calculating RFC (Response for a Class) metrics.
 *
 * RFC = M + R
 * Where:
 * - M = number of methods in the class
 * - R = number of unique external methods called from the class methods
 *
 * Tracks:
 * - Own methods (M)
 * - External method calls ($this->dependency->method())
 * - Static calls (SomeClass::staticMethod())
 * - Global function calls (array_map(), strlen(), etc.)
 * - Constructor calls (new SomeClass())
 *
 * Ignores:
 * - Internal calls ($this->method())
 * - self::, static::, parent:: calls (internal)
 * - Anonymous classes
 */
final class RfcVisitor extends NodeVisitorAbstract implements ResettableVisitorInterface
{
    /**
     * @var array<int, ClassRfcData> Physical class position => RFC data
     */
    private array $classes = [];

    private ?string $currentNamespace = null;

    /**
     * Stack of class contexts to handle nested classes.
     * null = anonymous class (ignored).
     *
     * @var list<int|null>
     */
    private array $classStack = [];

    /**
     * Track method nesting depth (to count external calls).
     * Using a counter instead of boolean to handle closures/anonymous classes inside methods.
     */
    private int $insideCallableDepth = 0;

    public function reset(): void
    {
        $this->classes = [];
        $this->currentNamespace = null;
        $this->classStack = [];
        $this->insideCallableDepth = 0;
    }

    /**
     * @return array<int, ClassRfcData>
     */
    public function getClassesData(): array
    {
        return $this->classes;
    }

    private function getCurrentClass(): ?int
    {
        if ($this->classStack === []) {
            return null;
        }

        return $this->classStack[array_key_last($this->classStack)];
    }

    public function enterNode(Node $node): ?int
    {
        // Track namespace
        if ($node instanceof Node\Stmt\Namespace_) {
            $this->currentNamespace = $node->name?->toString() ?? '';

            return null;
        }

        // Track class-like types (skip anonymous classes)
        if ($this->isClassLikeNode($node)) {
            $this->handleClassLikeNode($node);

            return null;
        }

        // Property hooks are class-rollable RFC callables, alongside methods.
        if ($node instanceof ClassMethod || $node instanceof PropertyHook) {
            $this->insideCallableDepth++;

            return null;
        }

        // Track external calls only inside class-rollable callables of named classes.
        if ($this->insideCallableDepth > 0 && $this->getCurrentClass() !== null) {
            $this->handleExternalCall($node);
        }

        return null;
    }

    private function handleClassLikeNode(Node $node): void
    {
        $className = $this->extractClassLikeName($node);
        $this->classStack[] = $className === null ? null : $node->getStartFilePos();

        // Only create metrics for named classes
        if ($className !== null) {
            $position = $node->getStartFilePos();
            $this->classes[$position] = new ClassRfcData(
                namespace: $this->currentNamespace,
                className: $className,
                line: $node->getStartLine(),
                startFilePos: $node->getStartFilePos(),
            );

            // Collect own methods
            if ($node instanceof Interface_) {
                // Interface methods are always abstract, but they ARE own methods of the interface
                $this->collectInterfaceMethods($node, $position);
            } elseif ($node instanceof Class_ || $node instanceof Trait_ || $node instanceof Enum_) {
                // Class_, Trait_, and Enum_: only non-abstract methods and property hooks
                $this->collectOwnMethods($node, $position);
                $this->collectOwnPropertyHooks($node, $position);
            }
        }
    }

    private function collectOwnMethods(Class_|Trait_|Enum_ $class, int $position): void
    {
        foreach ($class->getMethods() as $method) {
            if (!$method->isAbstract()) {
                $this->classes[$position]->addOwnMethod($method->name->toString());
            }
        }
    }

    private function collectInterfaceMethods(Interface_ $interface, int $position): void
    {
        foreach ($interface->getMethods() as $method) {
            $this->classes[$position]->addOwnMethod($method->name->toString());
        }
    }

    private function collectOwnPropertyHooks(Class_|Trait_|Enum_ $class, int $position): void
    {
        foreach ($class->stmts as $stmt) {
            if ($stmt instanceof Property) {
                $this->addOwnPropertyHooks($stmt->hooks, $position);
            }

            if ($stmt instanceof ClassMethod) {
                foreach ($stmt->params as $param) {
                    $this->addOwnPropertyHooks($param->hooks, $position);
                }
            }
        }
    }

    /** @param array<PropertyHook> $hooks */
    private function addOwnPropertyHooks(array $hooks, int $position): void
    {
        foreach ($hooks as $hook) {
            $this->classes[$position]->addOwnMethod($hook->name->toString());
        }
    }

    private function handleExternalCall(Node $node): void
    {
        $currentClass = $this->getCurrentClass();
        if ($currentClass === null) {
            return;
        }

        $position = $currentClass;

        if ($this->isNonExecutingCallableExpression($node)) {
            return;
        }

        match (true) {
            $node instanceof MethodCall => $this->handleMethodCall($node, $position),
            $node instanceof NullsafeMethodCall => $this->handleNullsafeMethodCall($node, $position),
            $node instanceof StaticCall => $this->handleStaticCall($node, $position),
            $node instanceof FuncCall => $this->handleFunctionCall($node, $position),
            $node instanceof New_ => $this->handleConstructorCall($node, $position),
            default => null,
        };
    }

    /**
     * First-class callable captures and PHP 8.5 clone-with expressions create
     * values; neither invokes an external RFC response target.
     */
    private function isNonExecutingCallableExpression(Node $node): bool
    {
        return ($node instanceof FuncCall && ($node->isFirstClassCallable() || $this->isCloneWithCall($node)))
            || ($node instanceof MethodCall && $node->isFirstClassCallable())
            || ($node instanceof StaticCall && $node->isFirstClassCallable());
    }

    private function isCloneWithCall(FuncCall $node): bool
    {
        return $node->name instanceof Name
            && strtolower($node->name->toString()) === 'clone';
    }

    private function handleMethodCall(MethodCall $node, int $position): void
    {
        $methodName = $node->name instanceof Identifier ? $node->name->toString() : null;
        if ($methodName === null) {
            return;
        }

        // Check if it's internal call ($this->method())
        $isInternalCall = $node->var instanceof Node\Expr\Variable && $node->var->name === 'this';

        if (!$isInternalCall) {
            // Use receiver identifier + method name as dedup key to distinguish
            // $repo->save() from $cache->save() (different receiver types).
            $receiverName = $this->extractReceiverName($node->var);
            $this->classes[$position]->addExternalMethod($receiverName . '->' . $methodName);
        }
    }

    private function handleNullsafeMethodCall(NullsafeMethodCall $node, int $position): void
    {
        $methodName = $node->name instanceof Identifier ? $node->name->toString() : null;
        if ($methodName === null) {
            return;
        }

        // Nullsafe calls are always external (cannot be $this?->method())
        $receiverName = $this->extractReceiverName($node->var);
        $this->classes[$position]->addExternalMethod($receiverName . '->' . $methodName);
    }

    private function handleStaticCall(StaticCall $node, int $position): void
    {
        $methodName = $node->name instanceof Identifier ? $node->name->toString() : null;
        if ($methodName === null || !$node->class instanceof Name) {
            return;
        }

        $className = $node->class->toString();

        // Ignore internal calls (self::, static::, parent::)
        if (!\in_array($className, ['self', 'static', 'parent'], true)) {
            $this->classes[$position]->addExternalMethod($className . '::' . $methodName);
        }
    }

    private function handleFunctionCall(FuncCall $node, int $position): void
    {
        if (!$node->name instanceof Name) {
            return;
        }

        $funcName = $node->name->toString();
        $this->classes[$position]->addExternalMethod($funcName);
    }

    private function handleConstructorCall(New_ $node, int $position): void
    {
        if (!$node->class instanceof Name) {
            return;
        }

        $className = $node->class->toString();

        // Ignore internal constructor calls (new self(), new static(), new parent())
        if (\in_array($className, ['self', 'static', 'parent'], true)) {
            return;
        }

        $this->classes[$position]->addExternalMethod($className . '::__construct');
    }

    public function leaveNode(Node $node): ?int
    {
        // Exit a class-rollable callable.
        if ($node instanceof ClassMethod || $node instanceof PropertyHook) {
            $this->insideCallableDepth--;

            return null;
        }

        // Exit class-like scope
        if ($this->isClassLikeNode($node)) {
            array_pop($this->classStack);

            return null;
        }

        // Exit namespace scope
        if ($node instanceof Node\Stmt\Namespace_) {
            $this->currentNamespace = null;

            return null;
        }

        return null;
    }

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

    private function isClassLikeNode(Node $node): bool
    {
        return $node instanceof Class_
            || $node instanceof Interface_
            || $node instanceof Trait_
            || $node instanceof Enum_;
    }

    /**
     * Extracts a stable receiver identifier for deduplication.
     *
     * - $var->method(): returns var name (e.g., 'repo')
     * - $this->prop->method(): returns prop name (e.g., 'userRepo')
     * - Other expressions: returns '*' (generic, no dedup across receivers)
     */
    private function extractReceiverName(Node\Expr $expr): string
    {
        // Simple variable: $repo->method()
        if ($expr instanceof Node\Expr\Variable && \is_string($expr->name)) {
            return $expr->name;
        }

        // Property fetch on $this: $this->repo->method()
        if ($expr instanceof Node\Expr\PropertyFetch
            && $expr->var instanceof Node\Expr\Variable
            && $expr->var->name === 'this'
            && $expr->name instanceof Identifier
        ) {
            return $expr->name->toString();
        }

        // Anything else (method chains, complex expressions)
        // Use unique identifier per AST node to avoid false deduplication
        return '*@' . spl_object_id($expr);
    }

}

/**
 * Value object holding RFC data for a single class.
 *
 * Mutable during collection phase, immutable after.
 */
final class ClassRfcData
{
    /**
     * @var list<string> Own methods
     */
    private array $ownMethods = [];

    /**
     * @var array<string, true> Unique external methods (map for deduplication)
     */
    private array $externalMethods = [];

    public function __construct(
        public readonly ?string $namespace = null,
        public readonly string $className = '',
        public readonly int $line = 0,
        public readonly int $startFilePos = 0,
    ) {}

    public function addOwnMethod(string $name): void
    {
        $this->ownMethods[] = $name;
    }

    public function addExternalMethod(string $name): void
    {
        $this->externalMethods[$name] = true;
    }

    /**
     * RFC = Own methods + External methods.
     */
    public function getRfc(): int
    {
        return \count($this->ownMethods) + \count($this->externalMethods);
    }

    public function getOwnMethodsCount(): int
    {
        return \count($this->ownMethods);
    }

    public function getExternalMethodsCount(): int
    {
        return \count($this->externalMethods);
    }
}
